<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WechatConfig;
use App\Models\WechatBinding;
use App\Models\WechatPushLog;
use App\Models\User;
use App\Models\SystemSetting;
use App\Models\Student;
use EasyWeChat\OfficialAccount\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WechatController extends Controller
{
    /**
     * 获取微信推送设置（系统管理员端）
     */
    public function getSettings(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => '无权限'], 403);
        }

        $settings = [
            'wechat_system_appid' => SystemSetting::get('wechat_system_appid', ''),
            'wechat_system_secret' => SystemSetting::get('wechat_system_secret') ? '******' : '',
            'wechat_system_token' => SystemSetting::get('wechat_system_token', ''),
            'wechat_system_template_id' => SystemSetting::get('wechat_system_template_id', ''),
            'wechat_callback_domain' => SystemSetting::get('wechat_callback_domain', ''),
            'wechat_use_system_enabled' => SystemSetting::get('wechat_use_system_enabled', '0') === '1',
            'wechat_custom_config_enabled' => SystemSetting::get('wechat_custom_config_enabled', '0') === '1',
            'wechat_manager_bind_enabled' => SystemSetting::get('wechat_manager_bind_enabled', '0') === '1',
        ];

        return response()->json($settings);
    }

    /**
     * 保存微信推送设置（系统管理员端）
     */
    public function saveSettings(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'admin'])) {
            return response()->json(['error' => '无权限'], 403);
        }

        $validated = $request->validate([
            'wechat_system_appid' => 'nullable|string|max:100',
            'wechat_system_secret' => 'nullable|string|max:100',
            'wechat_system_token' => 'nullable|string|max:100',
            'wechat_system_template_id' => 'nullable|string|max:100',
            'wechat_callback_domain' => 'nullable|string|max:255',
            'wechat_use_system_enabled' => 'boolean',
            'wechat_custom_config_enabled' => 'boolean',
            'wechat_manager_bind_enabled' => 'boolean',
        ]);

        if (isset($validated['wechat_system_appid'])) {
            SystemSetting::set('wechat_system_appid', $validated['wechat_system_appid']);
        }
        if (isset($validated['wechat_system_secret']) && $validated['wechat_system_secret'] !== '******') {
            SystemSetting::set('wechat_system_secret', $validated['wechat_system_secret']);
        }
        if (isset($validated['wechat_system_token'])) {
            SystemSetting::set('wechat_system_token', $validated['wechat_system_token']);
        }
        if (isset($validated['wechat_system_template_id'])) {
            SystemSetting::set('wechat_system_template_id', $validated['wechat_system_template_id']);
        }
        if (isset($validated['wechat_callback_domain'])) {
            SystemSetting::set('wechat_callback_domain', $validated['wechat_callback_domain']);
        }
        if (isset($validated['wechat_use_system_enabled'])) {
            SystemSetting::set('wechat_use_system_enabled', $validated['wechat_use_system_enabled'] ? '1' : '0');
        }
        if (isset($validated['wechat_custom_config_enabled'])) {
            SystemSetting::set('wechat_custom_config_enabled', $validated['wechat_custom_config_enabled'] ? '1' : '0');
        }
        if (isset($validated['wechat_manager_bind_enabled'])) {
            SystemSetting::set('wechat_manager_bind_enabled', $validated['wechat_manager_bind_enabled'] ? '1' : '0');
        }

        return response()->json(['message' => '保存成功']);
    }

    /**
     * 获取班主任配置列表（系统管理员端）
     */
    public function getTeacherConfigs(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => '无权限'], 403);
        }

        // 获取所有班主任（有管理班级的教师）
        $teachers = User::where('role', 'teacher')
            ->whereHas('teacherClasses')
            ->with(['teacherClasses', 'wechatConfig.bindings'])
            ->get()
            ->map(function ($teacher) {
                $config = $teacher->wechatConfig;
                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'classes' => $teacher->teacherClasses->pluck('name')->join(', '),
                    'config_type' => $config ? ($config->use_system ? '系统公众号' : '自己的公众号') : '未配置',
                    'is_verified' => $config ? $config->is_verified : false,
                    'binding_count' => $config ? $config->bindings->count() : 0,
                ];
            });

        return response()->json($teachers->values()->toArray());
    }

    /**
     * 获取绑定用户列表（系统管理员端）
     */
    public function getBindingList(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => '无权限'], 403);
        }

        $bindings = WechatBinding::with(['user', 'config.user'])
            ->get()
            ->map(function ($binding) {
                return [
                    'id' => $binding->id,
                    'user_name' => $binding->user->name ?? '-',
                    'user_role' => $binding->user->role ?? '-',
                    'nickname' => $binding->nickname ?? '-',
                    'config_owner' => $binding->config ? $binding->config->user->name : '系统公众号',
                    'created_at' => $binding->created_at->format('Y-m-d H:i'),
                ];
            });

        return response()->json($bindings->values()->toArray());
    }

    /**
     * 解除绑定（系统管理员端）
     */
    public function adminUnbind(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'admin'])) {
            return response()->json(['error' => '无权限'], 403);
        }

        $binding = WechatBinding::find($id);
        if (!$binding) {
            return response()->json(['error' => '绑定不存在'], 404);
        }

        $binding->delete();
        return response()->json(['message' => '解除绑定成功']);
    }

    /**
     * 获取班主任的微信配置（班主任端）
     */
    public function getTeacherConfig(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'teacher') {
            return response()->json(['error' => '无权限'], 403);
        }

        $config = WechatConfig::where('user_id', $user->id)->first();
        
        // 获取权限设置
        $useSystemEnabled = SystemSetting::get('wechat_use_system_enabled', '0') === '1';
        $customConfigEnabled = SystemSetting::get('wechat_custom_config_enabled', '0') === '1';
        
        // 获取系统公众号配置
        $systemConfig = null;
        if ($useSystemEnabled) {
            $systemAppid = SystemSetting::get('wechat_system_appid', '');
            if ($systemAppid) {
                $systemConfig = [
                    'appid' => $systemAppid,
                    'has_config' => true,
                ];
            }
        }

        // 获取绑定列表
        $bindings = [];
        if ($config) {
            $bindings = WechatBinding::where('config_id', $config->id)
                ->with('user')
                ->get()
                ->map(function ($binding) {
                    return [
                        'id' => $binding->id,
                        'user_id' => $binding->user_id,
                        'user_name' => $binding->user->name ?? '-',
                        'user_role' => $binding->user->role ?? '-',
                        'nickname' => $binding->nickname ?? '-',
                        'created_at' => $binding->created_at->format('Y-m-d H:i'),
                    ];
                });
        }

        // 我自己的绑定状态
        $myBinding = WechatBinding::where('user_id', $user->id)->first();

        return response()->json([
            'use_system_enabled' => $useSystemEnabled,
            'custom_config_enabled' => $customConfigEnabled,
            'system_config' => $systemConfig,
            'config' => $config ? [
                'id' => $config->id,
                'use_system' => $config->use_system,
                'appid' => $config->appid,
                'token' => $config->token,
                'template_id' => $config->template_id,
                'is_verified' => $config->is_verified,
                'has_secret' => !empty($config->getDecryptedAppsecret()),
            ] : null,
            'bindings' => $bindings,
            'my_binding' => $myBinding ? [
                'nickname' => $myBinding->nickname,
                'bound_at' => $myBinding->created_at->format('Y-m-d H:i'),
            ] : null,
            'callback_url' => $this->getCallbackUrl('teacher/' . $user->id),
            'test_account_url' => 'https://mp.weixin.qq.com/debug/cgi-bin/sandbox?t=sandbox/login',
        ]);
    }

    /**
     * 保存班主任的微信配置
     */
    public function saveTeacherConfig(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'teacher') {
            return response()->json(['error' => '无权限'], 403);
        }

        $validated = $request->validate([
            'use_system' => 'required|boolean',
            'appid' => 'nullable|string|max:100',
            'appsecret' => 'nullable|string|max:100',
            'token' => 'nullable|string|max:100',
            'template_id' => 'nullable|string|max:100',
        ]);

        // 检查权限
        if ($validated['use_system']) {
            $useSystemEnabled = SystemSetting::get('wechat_use_system_enabled', '0') === '1';
            if (!$useSystemEnabled) {
                return response()->json(['error' => '系统公众号功能未开启'], 400);
            }
        } else {
            $customConfigEnabled = SystemSetting::get('wechat_custom_config_enabled', '0') === '1';
            if (!$customConfigEnabled) {
                return response()->json(['error' => '自定义公众号功能未开启'], 400);
            }
        }

        $config = WechatConfig::updateOrCreate(
            ['user_id' => $user->id],
            [
                'use_system' => $validated['use_system'],
                'appid' => $validated['appid'] ?? null,
                'appsecret' => ($validated['appsecret'] && $validated['appsecret'] !== '******') ? $validated['appsecret'] : null,
                'token' => $validated['token'] ?? null,
                'template_id' => $validated['template_id'] ?? null,
                'is_verified' => false,
            ]
        );

        return response()->json(['message' => '保存成功', 'config_id' => $config->id]);
    }

    /**
     * 验证班主任的微信配置
     */
    public function verifyTeacherConfig(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'teacher') {
            return response()->json(['error' => '无权限'], 403);
        }

        $config = WechatConfig::where('user_id', $user->id)->first();
        if (!$config) {
            return response()->json(['error' => '请先保存配置'], 400);
        }

        if ($config->use_system) {
            // 使用系统公众号，直接标记为已验证
            $config->update(['is_verified' => true, 'verified_at' => now()]);
            return response()->json(['message' => '验证成功']);
        }

        // 验证自定义配置
        if (!$config->appid || !$config->getDecryptedAppsecret()) {
            return response()->json(['error' => '请填写完整的公众号配置'], 400);
        }

        try {
            $app = new Application([
                'app_id' => $config->appid,
                'secret' => $config->getDecryptedAppsecret(),
                'token' => $config->token,
            ]);

            // 尝试获取 access_token 来验证配置
            $accessToken = $app->getAccessToken()->getToken();
            
            if ($accessToken) {
                $config->update(['is_verified' => true, 'verified_at' => now()]);
                return response()->json(['message' => '验证成功']);
            }
        } catch (\Exception $e) {
            Log::error('WeChat config verification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => '验证失败: ' . $e->getMessage()], 400);
        }

        return response()->json(['error' => '验证失败'], 400);
    }

    /**
     * 班主任解除用户绑定
     */
    public function teacherUnbind(Request $request, $bindingId)
    {
        $user = $request->user();
        if ($user->role !== 'teacher') {
            return response()->json(['error' => '无权限'], 403);
        }

        $config = WechatConfig::where('user_id', $user->id)->first();
        if (!$config) {
            return response()->json(['error' => '未配置公众号'], 400);
        }

        $binding = WechatBinding::where('id', $bindingId)
            ->where('config_id', $config->id)
            ->first();

        if (!$binding) {
            return response()->json(['error' => '绑定不存在'], 404);
        }

        $binding->delete();
        return response()->json(['message' => '解除绑定成功']);
    }

    /**
     * 获取学生管理员的绑定状态
     */
    public function getManagerBindStatus(Request $request)
    {
        try {
            $user = $request->user();
            
            // 检查是否为学生管理员（班级管理员或学生管理员）
            $student = Student::where('user_id', $user->id)->first();
            if (!$student) {
                return response()->json(['enabled' => false, 'message' => '非学生用户']);
            }
            
            if (!$student->is_class_admin && !$student->is_manager) {
                return response()->json(['enabled' => false, 'message' => '非班级管理员']);
            }

            // 检查权限
            $managerBindEnabled = SystemSetting::get('wechat_manager_bind_enabled', '0') === '1';
            if (!$managerBindEnabled) {
                return response()->json(['enabled' => false, 'message' => '班级管理员绑定功能未开启']);
            }

            // 获取班主任
            $teacherClass = $student->schoolClass;
            if (!$teacherClass) {
                return response()->json(['enabled' => false, 'message' => '未找到班级信息']);
            }

            $teacher = $teacherClass->teacher;
            if (!$teacher) {
                return response()->json(['enabled' => false, 'message' => '班级未分配班主任']);
            }

            // 获取班主任的公众号配置
            $config = WechatConfig::where('user_id', $teacher->id)
                ->where('use_system', false)
                ->where('is_verified', true)
                ->first();

            if (!$config) {
                return response()->json([
                    'enabled' => true,
                    'can_bind' => false,
                    'message' => '请提醒班主任配置自己的测试公众号',
                ]);
            }

            // 检查我的绑定状态
            $myBinding = WechatBinding::where('user_id', $user->id)
                ->where('config_id', $config->id)
                ->first();

            return response()->json([
                'enabled' => true,
                'can_bind' => true,
                'teacher_name' => $teacher->name,
                'config_id' => $config->id,
                'my_binding' => $myBinding ? [
                    'nickname' => $myBinding->nickname,
                    'bound_at' => $myBinding->created_at->format('Y-m-d H:i'),
                ] : null,
            ]);
        } catch (\Exception $e) {
            \Log::error('getManagerBindStatus error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['enabled' => false, 'message' => '获取状态失败']);
        }
    }

    /**
     * 学生管理员解除自己的绑定
     */
    public function managerUnbind(Request $request)
    {
        $user = $request->user();
        
        $binding = WechatBinding::where('user_id', $user->id)->first();
        if (!$binding) {
            return response()->json(['error' => '未绑定'], 400);
        }

        $binding->delete();
        return response()->json(['message' => '解除绑定成功']);
    }

    /**
     * 生成绑定二维码（需要用户先配置公众号）
     */
    public function getBindQrcode(Request $request)
    {
        $user = $request->user();
        $configId = $request->input('config_id');

        // 确定使用哪个配置
        if ($user->role === 'teacher') {
            $config = WechatConfig::where('user_id', $user->id)->first();
        } else {
            // 学生管理员使用班主任的配置
            $config = WechatConfig::find($configId);
        }

        if (!$config) {
            return response()->json(['error' => '未找到公众号配置'], 400);
        }

        if (!$config->is_verified) {
            return response()->json(['error' => '公众号配置未验证'], 400);
        }

        try {
            $appConfig = $this->getAppConfig($config);
            $app = new Application($appConfig);

            // 生成带参数的二维码
            $sceneStr = 'bind_' . $user->id . '_' . $config->id . '_' . time();
            
            $response = $app->getClient()->postJson('/cgi-bin/qrcode/create', [
                'expire_seconds' => 600, // 10分钟有效
                'action_name' => 'QR_STR_SCENE',
                'action_info' => [
                    'scene' => ['scene_str' => $sceneStr],
                ],
            ]);

            $data = $response->toArray();
            
            if (isset($data['ticket'])) {
                $qrcodeUrl = 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=' . urlencode($data['ticket']);
                return response()->json([
                    'qrcode_url' => $qrcodeUrl,
                    'expire_seconds' => 600,
                ]);
            }

            return response()->json(['error' => '生成二维码失败'], 500);
        } catch (\Exception $e) {
            Log::error('Generate QR code failed', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => '生成二维码失败: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 微信回调 - 系统公众号
     */
    public function callbackSystem(Request $request)
    {
        $appid = SystemSetting::get('wechat_system_appid');
        $secret = SystemSetting::get('wechat_system_secret');
        $token = SystemSetting::get('wechat_system_token');

        if (!$appid || !$secret || !$token) {
            return 'success';
        }

        return $this->handleCallback($request, [
            'app_id' => $appid,
            'secret' => $secret,
            'token' => $token,
        ], 0);
    }

    /**
     * 微信回调 - 班主任公众号
     */
    public function callbackTeacher(Request $request, $teacherId)
    {
        $config = WechatConfig::where('user_id', $teacherId)->first();
        if (!$config || $config->use_system) {
            return 'success';
        }

        $appConfig = $this->getAppConfig($config);
        if (!$appConfig) {
            return 'success';
        }

        return $this->handleCallback($request, $appConfig, $config->id);
    }

    /**
     * 处理微信回调
     */
    private function handleCallback(Request $request, array $appConfig, $configId)
    {
        try {
            $app = new Application($appConfig);

            // 验证签名
            if ($request->isMethod('get')) {
                $signature = $request->input('signature');
                $timestamp = $request->input('timestamp');
                $nonce = $request->input('nonce');
                $echostr = $request->input('echostr');

                $tmpArr = [$appConfig['token'], $timestamp, $nonce];
                sort($tmpArr, SORT_STRING);
                $tmpStr = implode($tmpArr);
                $tmpStr = sha1($tmpStr);

                if ($tmpStr === $signature) {
                    return response($echostr);
                }
                return response('验证失败', 403);
            }

            // 处理消息
            $server = $app->getServer();
            
            $server->with(function ($message) use ($configId) {
                $msgType = $message['MsgType'] ?? '';
                $event = $message['Event'] ?? '';
                $openid = $message['FromUserName'] ?? '';

                Log::info('WeChat message received', [
                    'config_id' => $configId,
                    'msg_type' => $msgType,
                    'event' => $event,
                    'openid' => $openid,
                ]);

                // 处理扫码事件
                if ($msgType === 'event' && in_array($event, ['subscribe', 'SCAN'])) {
                    $sceneStr = '';
                    if ($event === 'subscribe') {
                        // 新关注，scene在 EventKey 中，格式: qrscene_xxx
                        $eventKey = $message['EventKey'] ?? '';
                        if (strpos($eventKey, 'qrscene_') === 0) {
                            $sceneStr = substr($eventKey, 8);
                        }
                    } else {
                        // 已关注扫码
                        $sceneStr = $message['EventKey'] ?? '';
                    }

                    if ($sceneStr && strpos($sceneStr, 'bind_') === 0) {
                        // 解析场景值: bind_{user_id}_{config_id}_{timestamp}
                        $parts = explode('_', $sceneStr);
                        if (count($parts) >= 3) {
                            $userId = $parts[1];
                            $bindConfigId = $parts[2];

                            // 验证 config_id 匹配
                            if ($bindConfigId == $configId || ($configId === 0 && $bindConfigId === '0')) {
                                $this->bindUser($userId, $configId, $openid);
                                return '绑定成功！您将收到班级请假申请通知。';
                            }
                        }
                    }

                    return '欢迎关注！';
                }

                return '';
            });

            return $server->serve();
        } catch (\Exception $e) {
            Log::error('WeChat callback error', [
                'config_id' => $configId,
                'error' => $e->getMessage(),
            ]);
            return 'success';
        }
    }

    /**
     * 绑定用户
     */
    private function bindUser($userId, $configId, $openid)
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                return false;
            }

            // 获取用户信息
            $config = $configId ? WechatConfig::find($configId) : null;
            $appConfig = $config 
                ? $this->getAppConfig($config)
                : [
                    'app_id' => SystemSetting::get('wechat_system_appid'),
                    'secret' => SystemSetting::get('wechat_system_secret'),
                    'token' => SystemSetting::get('wechat_system_token'),
                ];

            $nickname = null;
            $headimgurl = null;

            try {
                $app = new Application($appConfig);
                $userInfo = $app->getClient()->get('/cgi-bin/user/info', [
                    'query' => ['openid' => $openid, 'lang' => 'zh_CN'],
                ])->toArray();

                $nickname = $userInfo['nickname'] ?? null;
                $headimgurl = $userInfo['headimgurl'] ?? null;
            } catch (\Exception $e) {
                Log::warning('Get WeChat user info failed', ['error' => $e->getMessage()]);
            }

            // 删除旧绑定
            WechatBinding::where('user_id', $userId)->delete();

            // 创建新绑定
            WechatBinding::create([
                'user_id' => $userId,
                'config_id' => $configId ?: null,
                'openid' => $openid,
                'nickname' => $nickname,
                'headimgurl' => $headimgurl,
            ]);

            Log::info('WeChat binding created', [
                'user_id' => $userId,
                'config_id' => $configId,
                'openid' => $openid,
                'nickname' => $nickname,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Bind user failed', [
                'user_id' => $userId,
                'config_id' => $configId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 获取App配置
     */
    private function getAppConfig(WechatConfig $config): ?array
    {
        if ($config->use_system) {
            return [
                'app_id' => SystemSetting::get('wechat_system_appid'),
                'secret' => SystemSetting::get('wechat_system_secret'),
                'token' => SystemSetting::get('wechat_system_token'),
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

    /**
     * 生成回调URL（使用可配置的域名）
     */
    private function getCallbackUrl(string $path = 'system'): string
    {
        $domain = SystemSetting::get('wechat_callback_domain', '');
        if ($domain) {
            $domain = rtrim($domain, '/');
            return $domain . '/api/wechat/callback/' . $path;
        }
        return url('/api/wechat/callback/' . $path);
    }

    /**
     * 获取微信推送状态（用于前端显示开关）
     */
    public function getWechatStatus(Request $request)
    {
        $user = $request->user();

        $useSystemEnabled = SystemSetting::get('wechat_use_system_enabled', '0') === '1';
        $customConfigEnabled = SystemSetting::get('wechat_custom_config_enabled', '0') === '1';
        $managerBindEnabled = SystemSetting::get('wechat_manager_bind_enabled', '0') === '1';

        $showTeacherMenu = false;
        $showManagerBind = false;

        if ($user->role === 'teacher') {
            $showTeacherMenu = $useSystemEnabled || $customConfigEnabled;
        }

        if ($user->role === 'student') {
            $student = Student::where('user_id', $user->id)->first();
            if ($student && ($student->is_class_admin || $student->is_manager) && $managerBindEnabled) {
                $showManagerBind = true;
            }
        }

        return response()->json([
            'show_teacher_menu' => $showTeacherMenu,
            'show_manager_bind' => $showManagerBind,
        ]);
    }
}
