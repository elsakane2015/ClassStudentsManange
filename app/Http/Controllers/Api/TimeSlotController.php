<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeSlot;
use Illuminate\Http\Request;

class TimeSlotController extends Controller
{
    /**
     * List all time slots
     */
    public function index(Request $request)
    {
        $schoolId = 1; // Default school
        
        $query = TimeSlot::where('school_id', $schoolId)
            ->orderBy('sort_order')
            ->orderBy('time_start');
        
        if (!$request->user() || !in_array($request->user()->role, ['admin', 'system_admin', 'school_admin'])) {
            $query->where('is_active', true);
        }
        
        return response()->json($query->get());
    }

    /**
     * Create a new time slot
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'time_start' => 'required|date_format:H:i',
            'time_end' => 'required|date_format:H:i',
            'period_ids' => 'nullable|array',
            'period_ids.*' => 'integer',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $schoolId = 1;
        $validated['school_id'] = $schoolId;

        $slot = TimeSlot::create($validated);
        return response()->json($slot, 201);
    }

    /**
     * Update a time slot
     */
    public function update(Request $request, TimeSlot $timeSlot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:50',
            'time_start' => 'sometimes|date_format:H:i',
            'time_end' => 'sometimes|date_format:H:i',
            'period_ids' => 'nullable|array',
            'period_ids.*' => 'integer',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $timeSlot->update($validated);
        return response()->json($timeSlot);
    }

    /**
     * Delete a time slot
     */
    public function destroy(Request $request, TimeSlot $timeSlot)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $timeSlot->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
