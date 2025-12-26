<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LeaveImageController extends Controller
{
    /**
     * Get leave image settings
     */
    public function settings(Request $request)
    {
        return response()->json([
            'enabled' => SystemSetting::get('leave_image_upload_enabled', 'true') === 'true',
            'max_count' => (int) SystemSetting::get('leave_image_max_count', '3'),
            'max_size_mb' => (int) SystemSetting::get('leave_image_max_size_mb', '5'),
            'allowed_formats' => SystemSetting::get('leave_image_allowed_formats', 'jpg,jpeg,png,gif,webp'),
            'auto_cleanup' => SystemSetting::get('leave_image_auto_cleanup', 'true') === 'true',
            'retention_days' => (int) SystemSetting::get('leave_image_retention_days', '90'),
        ]);
    }

    /**
     * Update leave image settings (admin only)
     */
    public function updateSettings(Request $request)
    {
        $user = $request->user();
        
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => '无权限'], 403);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'max_count' => 'required|integer|min:1|max:10',
            'max_size_mb' => 'required|integer|min:1|max:20',
            'allowed_formats' => 'required|string',
            'auto_cleanup' => 'required|boolean',
            'retention_days' => 'required|integer|min:1|max:365',
        ]);

        SystemSetting::set('leave_image_upload_enabled', $validated['enabled'] ? 'true' : 'false');
        SystemSetting::set('leave_image_max_count', (string) $validated['max_count']);
        SystemSetting::set('leave_image_max_size_mb', (string) $validated['max_size_mb']);
        SystemSetting::set('leave_image_allowed_formats', $validated['allowed_formats']);
        SystemSetting::set('leave_image_auto_cleanup', $validated['auto_cleanup'] ? 'true' : 'false');
        SystemSetting::set('leave_image_retention_days', (string) $validated['retention_days']);

        return response()->json(['message' => '设置已保存']);
    }

    /**
     * Upload a leave image
     */
    public function upload(Request $request)
    {
        // Check if upload is enabled
        if (SystemSetting::get('leave_image_upload_enabled', 'true') !== 'true') {
            return response()->json(['error' => '图片上传功能已关闭'], 403);
        }

        $maxSizeMb = (int) SystemSetting::get('leave_image_max_size_mb', '5');
        $allowedFormats = SystemSetting::get('leave_image_allowed_formats', 'jpg,jpeg,png,gif,webp');
        $allowedMimes = $this->formatsToMimes($allowedFormats);

        $request->validate([
            'image' => 'required|image|mimes:' . $allowedFormats . '|max:' . ($maxSizeMb * 1024),
        ], [
            'image.required' => '请选择图片',
            'image.image' => '请上传有效的图片文件',
            'image.mimes' => '只支持 ' . strtoupper($allowedFormats) . ' 格式',
            'image.max' => '图片大小不能超过 ' . $maxSizeMb . 'MB',
        ]);

        $file = $request->file('image');
        $year = date('Y');
        $month = date('m');
        $filename = Str::random(16) . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = "uploads/leave/{$year}/{$month}/{$filename}";

        Storage::disk('public')->put($path, file_get_contents($file));

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    /**
     * Delete an uploaded image
     */
    public function delete(Request $request)
    {
        $path = $request->input('path');
        
        if (!$path || !str_starts_with($path, 'uploads/leave/')) {
            return response()->json(['error' => '无效的路径'], 400);
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        return response()->json(['message' => '删除成功']);
    }

    /**
     * Convert format string to mime types
     */
    private function formatsToMimes(string $formats): string
    {
        // Laravel validation already accepts file extensions for mimes rule
        return $formats;
    }
}
