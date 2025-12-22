<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LeaveType;

class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        // Public/Auth for list (for usage in forms)
        // Admin for management.
        
        $types = LeaveType::where('is_active', true)->get();
        if ($request->user() && in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) {
             // Admin sees all
             $types = LeaveType::all();
        }
        
        return response()->json($types);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string|unique:leave_types,slug',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'student_requestable' => 'boolean',
            'color' => 'nullable|string|max:20',  // Added color
            'input_type' => 'nullable|string|in:none,time,period_select,duration_select',
            'input_config' => 'nullable|array'
        ]);
        
        // School ID logic
        $schoolId = 1; 
        if ($user->student) $schoolId = $user->student->school_id;
        elseif ($user->teacherClasses()->exists()) $schoolId = $user->teacherClasses()->first()->school_id;

        $type = LeaveType::create(array_merge($validated, ['school_id' => $schoolId]));

        return response()->json($type, 201);
    }

    public function update(Request $request, LeaveType $leaveType)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'slug' => 'sometimes|string|unique:leave_types,slug,'.$leaveType->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'student_requestable' => 'boolean',
            'color' => 'nullable|string|max:20',  // Added color
            'input_type' => 'nullable|string|in:none,time,period_select,duration_select',
            'input_config' => 'nullable|array'
        ]);

        // Self-Healing: Fix missing columns if needed
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('leave_types', 'input_type')) {
                 \Illuminate\Support\Facades\DB::statement("ALTER TABLE leave_types ADD COLUMN input_type VARCHAR(255) NULL AFTER description");
                 \Illuminate\Support\Facades\DB::statement("ALTER TABLE leave_types ADD COLUMN input_config JSON NULL AFTER input_type");
            }
        } catch (\Exception $e) {
            \Log::error('Auto-Fix Schema Failed: ' . $e->getMessage());
        }

        try {
            \DB::reconnect();
            $leaveType->update($validated);
        } catch (\Exception $e) {
            \Log::error('LeaveType Update Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json($leaveType);
    }

    public function destroy(Request $request, LeaveType $leaveType)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $leaveType->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
