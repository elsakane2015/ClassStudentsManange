<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\RollCallController;
use App\Models\AttendanceRecord;
use App\Models\RollCallRecord;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class RollCallSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        date_default_timezone_set('Asia/Shanghai');
    }

    private function makeRollCallRecord(array $attributes): RollCallRecord
    {
        $record = new RollCallRecord();
        $record->setDateFormat('Y-m-d H:i:s');
        $record->setRawAttributes($attributes, true);
        return $record;
    }

    public function test_manual_override_present_is_not_reverted_by_older_leave_record(): void
    {
        $record = $this->makeRollCallRecord([
            'status' => 'present',
            'leave_type_id' => null,
            'leave_detail' => null,
            'leave_status' => 'manual_override',
            'marked_at' => '2026-11-22 19:50:00',
            'updated_at' => '2026-11-22 19:50:00',
        ]);

        $olderLeaveInfo = [
            'leave_type_id' => 2,
            'detail' => '病假(全天)',
            'status' => 'leave',
            'updated_at' => Carbon::parse('2026-11-20 08:00:00', 'Asia/Shanghai'),
        ];

        $mutation = RollCallController::resolveSyncedRecordMutation($record, 'completed', $olderLeaveInfo);

        $this->assertNull($mutation);
    }

    public function test_manual_override_absent_is_not_reverted_by_same_second_or_older_leave_record(): void
    {
        $record = $this->makeRollCallRecord([
            'status' => 'absent',
            'leave_type_id' => null,
            'leave_detail' => null,
            'leave_status' => 'manual_override',
            'marked_at' => null,
            'updated_at' => '2026-11-22 19:51:00',
        ]);

        $leaveInfo = [
            'leave_type_id' => 3,
            'detail' => '事假(早操)',
            'status' => 'leave',
            'updated_at' => Carbon::parse('2026-11-22 19:51:00', 'Asia/Shanghai'),
        ];

        $this->assertNull(RollCallController::resolveSyncedRecordMutation($record, 'completed', $leaveInfo));
    }

    public function test_newer_attendance_record_after_manual_override_still_syncs_to_on_leave(): void
    {
        $record = $this->makeRollCallRecord([
            'status' => 'present',
            'leave_type_id' => null,
            'leave_detail' => null,
            'leave_status' => 'manual_override',
            'marked_at' => '2026-11-22 19:50:00',
            'updated_at' => '2026-11-22 19:50:00',
        ]);

        $newerLeaveInfo = [
            'leave_type_id' => 2,
            'detail' => '病假(早操)',
            'status' => 'leave',
            'updated_at' => Carbon::parse('2026-11-22 19:55:00', 'Asia/Shanghai'),
        ];

        $mutation = RollCallController::resolveSyncedRecordMutation($record, 'completed', $newerLeaveInfo);

        $this->assertSame([
            'status' => 'on_leave',
            'leave_type_id' => 2,
            'leave_detail' => '病假(早操)',
            'leave_status' => 'leave',
        ], $mutation);
    }

    public function test_manual_on_leave_fallback_is_not_reverted_when_no_attendance_record_exists(): void
    {
        $record = new RollCallRecord([
            'status' => 'on_leave',
            'leave_type_id' => 2,
            'leave_detail' => '病假(早操)',
            'leave_status' => 'manual_on_leave',
        ]);

        $this->assertNull(RollCallController::resolveSyncedRecordMutation($record, 'completed', null));
    }

    public function test_synced_on_leave_without_changes_returns_null(): void
    {
        $record = new RollCallRecord([
            'status' => 'on_leave',
            'leave_type_id' => 2,
            'leave_detail' => '病假(早操)',
            'leave_status' => 'leave',
        ]);

        $leaveInfo = [
            'leave_type_id' => 2,
            'detail' => '病假(早操)',
            'status' => 'leave',
            'updated_at' => Carbon::parse('2026-11-22 19:52:00', 'Asia/Shanghai'),
        ];

        $this->assertNull(RollCallController::resolveSyncedRecordMutation($record, 'completed', $leaveInfo));
    }

    public function test_revoked_leave_restores_completed_record_to_present_and_in_progress_unmarked_to_pending(): void
    {
        $completedRecord = new RollCallRecord([
            'status' => 'on_leave',
            'leave_type_id' => 2,
            'leave_detail' => '病假(早操)',
            'leave_status' => 'leave',
            'marked_at' => null,
        ]);

        $this->assertSame([
            'status' => 'present',
            'leave_type_id' => null,
            'leave_detail' => null,
            'leave_status' => null,
        ], RollCallController::resolveSyncedRecordMutation($completedRecord, 'completed', null));

        $inProgressRecord = new RollCallRecord([
            'status' => 'on_leave',
            'leave_type_id' => 2,
            'leave_detail' => '病假(早操)',
            'leave_status' => 'leave',
            'marked_at' => null,
        ]);

        $this->assertSame([
            'status' => 'pending',
            'leave_type_id' => null,
            'leave_detail' => null,
            'leave_status' => null,
        ], RollCallController::resolveSyncedRecordMutation($inProgressRecord, 'in_progress', null));
    }

    public function test_attendance_cleanup_rules_preserve_multi_period_and_self_applied_leaves(): void
    {
        $rollCallId = 88;
        $rollCallPeriods = [2]; // 早操

        // 1. 本次点名产生的旷课记录：必须删除
        $ownRollCallRecord = new AttendanceRecord([
            'source_type' => 'roll_call',
            'source_id' => 88,
            'period_id' => 2,
        ]);
        $this->assertTrue(RollCallController::shouldDeleteAttendanceOnRollCallUpdate($ownRollCallRecord, $rollCallId, $rollCallPeriods, false));

        // 2. 其他点名产生的旷课记录：不能删除
        $otherRollCallRecord = new AttendanceRecord([
            'source_type' => 'roll_call',
            'source_id' => 99,
            'period_id' => 9,
        ]);
        $this->assertFalse(RollCallController::shouldDeleteAttendanceOnRollCallUpdate($otherRollCallRecord, $rollCallId, $rollCallPeriods, true));

        // 3. 本次点名页改请假生成的手动记录：必须删除
        $fromRollCallLeave = new AttendanceRecord([
            'source_type' => 'manual',
            'period_id' => 2,
            'details' => ['from_roll_call_id' => 88, 'period_ids' => [2]],
        ]);
        $this->assertTrue(RollCallController::shouldDeleteAttendanceOnRollCallUpdate($fromRollCallLeave, $rollCallId, $rollCallPeriods, false));

        // 4. 仅针对早操的单节手动记录：改为已到时应同步删除，改为缺勤时留给后续 updateOrCreate
        $exclusiveManual = new AttendanceRecord([
            'source_type' => 'manual',
            'is_self_applied' => false,
            'period_id' => 2,
            'details' => ['period_ids' => [2]],
        ]);
        $this->assertTrue(RollCallController::shouldDeleteAttendanceOnRollCallUpdate($exclusiveManual, $rollCallId, $rollCallPeriods, true));
        $this->assertFalse(RollCallController::shouldDeleteAttendanceOnRollCallUpdate($exclusiveManual, $rollCallId, $rollCallPeriods, false));

        // 5. 跨多节次的手动记录（如早操 + 第1-2节）：绝不误删
        $multiPeriodManual = new AttendanceRecord([
            'source_type' => 'manual',
            'is_self_applied' => false,
            'period_id' => 2,
            'details' => ['period_ids' => [2, 3, 4]],
        ]);
        $this->assertFalse(RollCallController::shouldDeleteAttendanceOnRollCallUpdate($multiPeriodManual, $rollCallId, $rollCallPeriods, true));

        // 6. 学生自主请假 / 请假审批记录：绝不误删
        $selfApplied = new AttendanceRecord([
            'source_type' => 'self_applied',
            'is_self_applied' => true,
            'period_id' => 2,
            'details' => ['period_ids' => [2]],
        ]);
        $this->assertFalse(RollCallController::shouldDeleteAttendanceOnRollCallUpdate($selfApplied, $rollCallId, $rollCallPeriods, true));

        // 7. 全天请假（period_id = null）：绝不误删
        $fullDayManual = new AttendanceRecord([
            'source_type' => 'manual',
            'is_self_applied' => false,
            'period_id' => null,
            'details' => [],
        ]);
        $this->assertFalse(RollCallController::shouldDeleteAttendanceOnRollCallUpdate($fullDayManual, $rollCallId, $rollCallPeriods, true));
    }
}
