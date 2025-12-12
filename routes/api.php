<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\StudentController;

// Public routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Leave Requests
    Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
    Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
    Route::post('/leave-requests/{id}/approve', [LeaveRequestController::class, 'approve']);
    Route::post('/leave-requests/{id}/reject', [LeaveRequestController::class, 'reject']);

    // Attendance
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/calendar', [AttendanceController::class, 'calendar']);
    Route::post('/attendance', [AttendanceController::class, 'store']); // Manual add

    // Students / Admin
    Route::post('/students/import', [StudentController::class, 'import']);
    Route::get('/students/import-template', [StudentController::class, 'template']);
    
    // Class/Utility routes (Placeholder for now)
    Route::get('/class-periods', function(Request $request) {
        // Simple closure for now, should be in controller ideally
        return \App\Models\ClassPeriod::where('school_id', $request->user()->student->school_id ?? 1)->orderBy('ordinal')->get();
    });
});
