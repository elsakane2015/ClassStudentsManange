<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Department;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        // Public/Auth can list? Assuming needed for dropdowns.
        $departments = Department::all();
        return response()->json($departments);
    }

    public function store(Request $request)
    {
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) return response()->json(['error' => 'Unauthorized'], 403);

        $validated = $request->validate([
            'name' => 'required|string',
            'school_id' => 'required|exists:schools,id' // In a real app, might infer from admin's context
        ]);

        $dept = Department::create($validated);
        return response()->json($dept, 201);
    }

    public function update(Request $request, Department $department)
    {
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) return response()->json(['error' => 'Unauthorized'], 403);

        $validated = $request->validate([
            'name' => 'required|string',
        ]);

        $department->update($validated);
        return response()->json($department);
    }

    public function destroy(Request $request, Department $department)
    {
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) return response()->json(['error' => 'Unauthorized'], 403);
        
        $department->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
