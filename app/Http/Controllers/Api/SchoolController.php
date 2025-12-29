<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    /**
     * Get school info (public API - no auth required)
     */
    public function info()
    {
        // Get the first (and usually only) school
        $school = School::first();
        
        if (!$school) {
            return response()->json([
                'id' => null,
                'name' => '智慧校园考勤系统',
            ]);
        }

        return response()->json([
            'id' => $school->id,
            'name' => $school->name,
        ]);
    }

    /**
     * Update school info (admin only)
     */
    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
        ], [
            'name.required' => '请输入学校名称',
            'name.max' => '学校名称不能超过100个字符',
        ]);

        $user = $request->user();
        
        // Only system_admin can update school info
        if (!in_array($user->role, ['system_admin', 'admin'])) {
            return response()->json(['error' => '权限不足'], 403);
        }

        $school = School::first();
        
        if (!$school) {
            $school = School::create(['name' => $request->name]);
        } else {
            $school->update(['name' => $request->name]);
        }

        return response()->json([
            'success' => true,
            'message' => '学校名称已更新',
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
            ]
        ]);
    }
}
