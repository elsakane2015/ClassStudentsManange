<?php

namespace App\Services;

use App\Models\WechatConfig;
use App\Models\WechatBinding;
use App\Models\WechatPushLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Student;
use App\Models\AttendanceRecord;
use EasyWeChat\OfficialAccount\Application;
use Illuminate\Support\Facades\Log;

class WechatPushService
{
    /**
     * 发送请假申请通知
     * @param AttendanceRecord $attendanceRecord 考勤记录（请假申请）
     */
    public function sendLeaveRequestNotification(AttendanceRecord $attendanceRecord): array
    {
        $results = [];

        // 获取申请学生信息
        $student = $attendanceRecord->student;
        if (!$student) {
            return ['error' => '未找到学生信息'];
        }

        $class = $student->schoolClass;
        if (!$class) {
            return ['error' => '未找到班级信息'];
        }

        // 获取班主任
        $teacher = $class->teacher;
        if (!$teacher) {
            return ['error' => '班级未分配班主任'];
        }

        // 获取班主任的公众号配置
        $config = WechatConfig::where('user_id', $teacher->id)
            ->where('is_verified', true)
            ->first();

        if (!$config) {
            return ['error' => '班主任未配置公众号'];
        }

        // 确定模板ID
        $templateId = $config->use_system
            ? SystemSetting::get('wechat_system_template_id')
            : $config->template_id;

        if (!$templateId) {
            return ['error' => '未配置模板ID'];
        }

        // 获取需要推送的用户
        $bindingsToNotify = WechatBinding::where('config_id', $config->id)->get();

        foreach ($bindingsToNotify as $binding) {
            $result = $this->sendTemplateMessage(
                $config,
                $binding,
                $templateId,
                $this->buildLeaveRequestData($attendanceRecord, $student, $class),
                'attendance_record',
                $attendanceRecord->id
            );
            $results[] = $result;
        }

        return $results;
    }

    /**
     * 构建请假申请通知数据
     */
    private function buildLeaveRequestData(AttendanceRecord $record, $student, $class): array
    {
        $leaveType = $record->leaveType;
        $timeText = $this->formatLeaveTime($record);

        return [
            'first' => ['value' => '您好，有一条新的请假申请需要审批'],
            'keyword1' => ['value' => $student->user->name ?? '未知'],
            'keyword2' => ['value' => $class->name ?? '未知'],
            'keyword3' => ['value' => $leaveType->name ?? '未知'],
            'keyword4' => ['value' => $timeText],
            'keyword5' => ['value' => $record->reason ?? '无'],
            'keyword6' => ['value' => $record->created_at->format('Y-m-d H:i')],
            'remark' => ['value' => '请及时登录系统审批'],
        ];
    }

    /**
     * 格式化请假时间
     */
    private function formatLeaveTime(AttendanceRecord $record): string
    {
        $date = $record->date ? $record->date->format('m月d日') : '';
        $details = $record->details ?? [];
        if (is_string($details)) {
            $details = json_decode($details, true) ?? [];
        }
        $option = $details['option'] ?? '';

        $optionLabels = [
            'morning_half' => '上午',
            'afternoon_half' => '下午',
            'full_day' => '全天',
            'zcao' => '早操',
            'wcao' => '晚操',
            'morning_exercise' => '早操',
            'evening_exercise' => '晚操',
        ];

        $optionText = $optionLabels[$option] ?? '';
        return $date . ($optionText ? " ({$optionText})" : '');
    }

    /**
     * 发送模板消息
     */
    private function sendTemplateMessage(
        WechatConfig $config,
        WechatBinding $binding,
        string $templateId,
        array $data,
        string $relatedType,
        int $relatedId
    ): array {
        $appConfig = $this->getAppConfig($config);
        if (!$appConfig) {
            return ['success' => false, 'error' => '公众号配置无效'];
        }

        try {
            $app = new Application($appConfig);

            // 获取跳转URL（使用回调域名设置）
            $clickUrl = SystemSetting::get('wechat_callback_domain', '');
            if (!$clickUrl) {
                $clickUrl = config('app.url', '');
            }

            $response = $app->getClient()->postJson('/cgi-bin/message/template/send', [
                'touser' => $binding->openid,
                'template_id' => $templateId,
                'url' => $clickUrl, // 点击消息后跳转的地址
                'data' => $data,
            ]);

            $result = $response->toArray();
            $success = ($result['errcode'] ?? -1) === 0;

            // 记录日志
            WechatPushLog::create([
                'config_id' => $config->id,
                'user_id' => $binding->user_id,
                'openid' => $binding->openid,
                'template_id' => $templateId,
                'content' => $data,
                'status' => $success ? 'success' : 'failed',
                'error_msg' => $success ? null : ($result['errmsg'] ?? '未知错误'),
                'related_type' => $relatedType,
                'related_id' => $relatedId,
            ]);

            if (!$success) {
                Log::warning('WeChat template message failed', [
                    'user_id' => $binding->user_id,
                    'openid' => $binding->openid,
                    'error' => $result['errmsg'] ?? '未知错误',
                ]);

                // 如果用户取消关注，删除绑定
                if (($result['errcode'] ?? 0) === 43004) {
                    $binding->delete();
                }
            }

            return [
                'success' => $success,
                'user_id' => $binding->user_id,
                'error' => $success ? null : ($result['errmsg'] ?? '未知错误'),
            ];
        } catch (\Exception $e) {
            Log::error('Send template message exception', [
                'user_id' => $binding->user_id,
                'error' => $e->getMessage(),
            ]);

            WechatPushLog::create([
                'config_id' => $config->id,
                'user_id' => $binding->user_id,
                'openid' => $binding->openid,
                'template_id' => $templateId,
                'content' => $data,
                'status' => 'failed',
                'error_msg' => $e->getMessage(),
                'related_type' => $relatedType,
                'related_id' => $relatedId,
            ]);

            return [
                'success' => false,
                'user_id' => $binding->user_id,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取App配置
     */
    private function getAppConfig(WechatConfig $config): ?array
    {
        if ($config->use_system) {
            $appid = SystemSetting::get('wechat_system_appid');
            $secret = SystemSetting::get('wechat_system_secret');
            $token = SystemSetting::get('wechat_system_token');

            if (!$appid || !$secret) {
                return null;
            }

            return [
                'app_id' => $appid,
                'secret' => $secret,
                'token' => $token,
            ];
        }

        $secret = $config->getDecryptedAppsecret();
        if (!$config->appid || !$secret) {
            return null;
        }

        return [
            'app_id' => $config->appid,
            'secret' => $secret,
            'token' => $config->token,
        ];
    }
}
