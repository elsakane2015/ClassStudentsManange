<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            $generatedNames = DB::table('leave_types')
                ->where('school_id', $schoolId)
                ->pluck('name')
                ->map(fn ($name) => $name . '（夜自习）');

            if ($generatedNames->isNotEmpty()) {
                $generatedStatuses = DB::table('evening_study_statuses')
                    ->where('school_id', $schoolId)
                    ->whereIn('name', $generatedNames)
                    ->get(['id']);

                foreach ($generatedStatuses as $generatedStatus) {
                    $isReferenced = DB::table('attendance_records')
                        ->where('requested_evening_status_id', $generatedStatus->id)
                        ->orWhere('evening_study_status_id', $generatedStatus->id)
                        ->exists();

                    if (!$isReferenced) {
                        DB::table('evening_study_statuses')->where('id', $generatedStatus->id)->delete();
                        continue;
                    }

                    DB::table('evening_study_statuses')->where('id', $generatedStatus->id)->update([
                        'student_requestable' => false,
                        'leave_type_id' => null,
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->renameOrDisableDuplicate($schoolId, '病假在宿舍', '在宿舍');
            $this->renameOrDisableDuplicate($schoolId, '病假在家', '在家');

            $defaults = [
                ['name' => '在宿舍', 'color' => 'blue', 'base_status' => 'excused'],
                ['name' => '在家', 'color' => 'indigo', 'base_status' => 'excused'],
                ['name' => '在学生会', 'color' => 'purple', 'base_status' => 'excused'],
                ['name' => '在图书馆', 'color' => 'cyan', 'base_status' => 'excused'],
                ['name' => '在教室', 'color' => 'green', 'base_status' => 'present'],
            ];

            foreach ($defaults as $status) {
                $existing = DB::table('evening_study_statuses')
                    ->where('school_id', $schoolId)
                    ->where('name', $status['name'])
                    ->first();
                $values = [
                    ...$status,
                    'student_requestable' => true,
                    'leave_type_id' => null,
                    'is_active' => true,
                    'updated_at' => now(),
                ];

                if ($existing) {
                    DB::table('evening_study_statuses')->where('id', $existing->id)->update($values);
                    continue;
                }

                DB::table('evening_study_statuses')->insert([
                    'school_id' => $schoolId,
                    ...$values,
                    'is_default' => false,
                    'sort_order' => (int) DB::table('evening_study_statuses')
                        ->where('school_id', $schoolId)
                        ->max('sort_order') + 1,
                    'created_at' => now(),
                ]);
            }

            DB::table('evening_study_statuses')
                ->where('school_id', $schoolId)
                ->where('name', '暂停住宿')
                ->update([
                    'student_requestable' => false,
                    'leave_type_id' => null,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

            DB::table('evening_study_statuses')
                ->where('school_id', $schoolId)
                ->whereNotNull('leave_type_id')
                ->update(['leave_type_id' => null, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Status-to-leave-type bindings cannot be reconstructed without changing history.
    }

    private function renameOrDisableDuplicate(int $schoolId, string $oldName, string $newName): void
    {
        $old = DB::table('evening_study_statuses')
            ->where('school_id', $schoolId)
            ->where('name', $oldName)
            ->first();
        if (!$old) {
            return;
        }

        $duplicate = DB::table('evening_study_statuses')
            ->where('school_id', $schoolId)
            ->where('name', $newName)
            ->where('id', '!=', $old->id)
            ->exists();

        DB::table('evening_study_statuses')->where('id', $old->id)->update([
            'name' => $duplicate ? $oldName : $newName,
            'student_requestable' => !$duplicate,
            'leave_type_id' => null,
            'is_active' => !$duplicate,
            'updated_at' => now(),
        ]);
    }
};
