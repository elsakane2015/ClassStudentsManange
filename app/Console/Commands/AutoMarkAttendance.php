<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SystemSetting;
use App\Models\Student;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AutoMarkAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:auto-mark {--force : Force run regardless of time}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically mark attendance for students who have not checked in by the configured time.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            \Illuminate\Support\Facades\DB::reconnect();
        } catch (\Exception $e) {
            $this->error("Failed to reconnect to DB: " . $e->getMessage());
        }

        $setting = SystemSetting::where('key', 'attendance_auto_mark_time')->first();
        
        if (!$setting) {
            $this->info('Auto-mark time not configured.');
            return;
        }

        $autoMarkTimeStr = $setting->value; // e.g., "08:05"
        
        try {
            // Logic: Parse the time string today
            // If checking format H:i
            $autoMarkTime = Carbon::createFromFormat('H:i', $autoMarkTimeStr);
        } catch (\Exception $e) {
            $this->error('Invalid time format in settings. Expected HH:MM.');
            return;
        }

        $now = Carbon::now();

        // If it's not yet time, and not forced, exit
        // We compare time of day.
        // Assuming this runs every minute. 
        // Strict check: if ($now->format('H:i') === $autoMarkTime->format('H:i')) 
        // Robust check: if ($now->greaterThanOrEqualTo($autoMarkTime))
        // But we don't want to run it repeatedly every minute and spam logs, though the query logic (checking if record exists) makes it idempotent.
        
        if (!$this->option('force') && $now->format('H:i') < $autoMarkTime->format('H:i')) {
            $this->info('Not yet time to auto-mark. Configured: ' . $autoMarkTimeStr . ', Current: ' . $now->format('H:i'));
            return;
        }

        $this->info('Starting auto-attendance marking...');

        $date = $now->format('Y-m-d');
        
        // chunking students for memory efficiency if large
        Student::chunk(100, function ($students) use ($date) {
            foreach ($students as $student) {
                // 1. Check if attendance already exists
                $exists = AttendanceRecord::where('student_id', $student->id)
                            ->where('date', $date)
                            ->exists();
                
                if ($exists) {
                    continue;
                }

                // 2. Check if there is an APPROVED leave request covering today
                // Overlap: start <= today AND end >= today
                $onLeave = LeaveRequest::where('student_id', $student->id)
                            ->where('status', 'approved')
                            ->where('start_date', '<=', $date)
                            ->where('end_date', '>=', $date)
                            ->exists();

                if ($onLeave) {
                    // Optionally create a 'leave' record in attendance table?
                    // For now, let's say we mark them as 'leave' to be explicit in the daily report
                     AttendanceRecord::create([
                        'student_id' => $student->id,
                        'class_id' => $student->class_id, // Ensure this field exists and is filled in Student model
                        'school_id' => $student->school_id,
                        'date' => $date,
                        'status' => 'leave',
                        'source_type' => 'auto', // 'system' or 'auto'
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]);
                    continue;
                }

                // 3. Mark as Present
                AttendanceRecord::create([
                    'student_id' => $student->id,
                    'class_id' => $student->class_id,
                    'school_id' => $student->school_id,
                    'date' => $date,
                    'status' => 'present',
                    'source_type' => 'auto',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        });

        $this->info('Auto-attendance marking completed.');
        Log::info('Auto-attendance marking completed for ' . $date);
    }
}
