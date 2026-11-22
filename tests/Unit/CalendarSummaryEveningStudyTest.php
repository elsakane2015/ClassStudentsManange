<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AttendanceController;
use App\Models\AttendanceRecord;
use App\Models\BoardingSuspension;
use App\Models\EveningStudySession;
use App\Models\EveningStudyStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Semester;
use App\Models\Student;
use PHPUnit\Framework\TestCase;

class CalendarSummaryEveningStudyTest extends TestCase
{
    public function test_excludes_normal_evening_study_roll_call_records(): void
    {
        $normalRollCall = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'present',
            'leave_type_id' => null,
            'is_self_applied' => false,
            'approval_status' => null,
            'source_type' => 'evening_study',
            'status_name_snapshot' => '正常',
        ]);

        $manualNormalRollCall = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'present',
            'leave_type_id' => null,
            'is_self_applied' => false,
            'approval_status' => null,
            'source_type' => 'evening_study_manual',
            'status_name_snapshot' => '正常',
        ]);

        $regularPresent = new AttendanceRecord([
            'scene' => 'regular',
            'status' => 'present',
            'leave_type_id' => null,
            'is_self_applied' => false,
            'approval_status' => null,
        ]);

        $this->assertFalse(AttendanceController::shouldIncludeInCalendarSummary($normalRollCall));
        $this->assertFalse(AttendanceController::shouldIncludeInCalendarSummary($manualNormalRollCall));
        $this->assertFalse(AttendanceController::shouldIncludeInCalendarSummary($regularPresent));
    }

    public function test_includes_evening_study_anomalies_and_pending_leaves(): void
    {
        // 学生待审夜自习请假：底层 status 仍为 present，但属于自主待审申请，应展示
        $pendingSelfLeave = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'present',
            'leave_type_id' => 1,
            'is_self_applied' => true,
            'approval_status' => 'pending',
        ]);

        // 无 leave_type_id 的待审夜自习申请
        $pendingStatusOnly = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'present',
            'leave_type_id' => null,
            'is_self_applied' => true,
            'approval_status' => 'pending',
        ]);

        // 班主任标记夜自习迟到（base_status=present 但带 leave_type_id）
        $teacherMarkedLate = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'present',
            'leave_type_id' => 2,
            'is_self_applied' => false,
            'approval_status' => null,
            'source_type' => 'teacher_evening_leave',
        ]);

        // 值班老师夜自习点名标记非正常状态（在图书馆 / 暂停住宿 / 缺勤）
        $eveningLibrary = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'excused',
            'leave_type_id' => null,
            'is_self_applied' => false,
            'approval_status' => null,
            'status_name_snapshot' => '在图书馆',
        ]);

        $eveningAbsent = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'absent',
            'leave_type_id' => null,
            'is_self_applied' => false,
            'approval_status' => null,
            'status_name_snapshot' => '缺勤',
        ]);

        $this->assertTrue(AttendanceController::shouldIncludeInCalendarSummary($pendingSelfLeave));
        $this->assertTrue(AttendanceController::shouldIncludeInCalendarSummary($pendingStatusOnly));
        $this->assertTrue(AttendanceController::shouldIncludeInCalendarSummary($teacherMarkedLate));
        $this->assertTrue(AttendanceController::shouldIncludeInCalendarSummary($eveningLibrary));
        $this->assertTrue(AttendanceController::shouldIncludeInCalendarSummary($eveningAbsent));
    }

    public function test_resolves_evening_study_labels_without_unknown_fallback(): void
    {
        // 1. 有 leaveType（例如病假 + 夜自习·在宿舍）保持原有格式
        $withLeaveType = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'excused',
            'period_name_snapshot' => '夜自习',
            'is_self_applied' => false,
        ]);
        $withLeaveType->setRelation('leaveType', new LeaveType(['name' => '病假']));
        $withLeaveType->setRelation('eveningStudyStatus', new EveningStudyStatus(['name' => '在宿舍']));

        $this->assertSame(
            ['type' => '病假', 'option' => '夜自习·在宿舍'],
            AttendanceController::resolveCalendarSummaryLabels($withLeaveType)
        );

        // 2. 无 leaveType（例如值班老师点名标记「暂停住宿」「在图书馆」）：直接用状态名做 type，避免出现“未知”
        $statusOnly = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'excused',
            'period_name_snapshot' => '夜自习',
            'status_name_snapshot' => '暂停住宿',
            'is_self_applied' => false,
        ]);
        $statusOnly->setRelation('eveningStudyStatus', new EveningStudyStatus(['name' => '暂停住宿']));

        $this->assertSame(
            ['type' => '暂停住宿', 'option' => '夜自习'],
            AttendanceController::resolveCalendarSummaryLabels($statusOnly)
        );

        // 3. 学生待审无 leaveType 夜自习申请：优先用 requestedEveningStatus
        $pendingStatusOnly = new AttendanceRecord([
            'scene' => 'evening_study',
            'status' => 'present',
            'period_name_snapshot' => '夜自习',
            'is_self_applied' => true,
            'approval_status' => 'pending',
        ]);
        $pendingStatusOnly->setRelation('eveningStudyStatus', new EveningStudyStatus(['name' => '正常']));
        $pendingStatusOnly->setRelation('requestedEveningStatus', new EveningStudyStatus(['name' => '在图书馆']));

        $this->assertSame(
            ['type' => '在图书馆', 'option' => '夜自习'],
            AttendanceController::resolveCalendarSummaryLabels($pendingStatusOnly)
        );

        // 4. 常规课请假缺 leave_type 兜底“请假”而不是“未知”
        $regularExcused = new AttendanceRecord([
            'scene' => 'regular',
            'status' => 'excused',
        ]);
        $this->assertSame(
            ['type' => '请假', 'option' => null],
            AttendanceController::resolveCalendarSummaryLabels($regularExcused)
        );
    }

    public function test_date_only_models_serialize_as_ymd_without_utc_day_shift(): void
    {
        $prevTz = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');

        try {
            $attendance = new AttendanceRecord(['date' => '2026-11-20']);
            $evening = new EveningStudySession(['attendance_date' => '2026-11-20']);
            $suspension = new BoardingSuspension(['start_date' => '2026-11-20', 'end_date' => '2026-11-22']);
            $semester = new Semester(['start_date' => '2026-09-01']);
            $leave = new LeaveRequest(['start_date' => '2026-11-20', 'end_date' => '2026-11-21']);
            $student = new Student();
            $student->birthdate = '2008-05-01';

            $this->assertSame('2026-11-20', $attendance->toArray()['date']);
            $this->assertSame('2026-11-20', $evening->toArray()['attendance_date']);
            $this->assertSame('2026-11-20', $suspension->toArray()['start_date']);
            $this->assertSame('2026-11-22', $suspension->toArray()['end_date']);
            $this->assertSame('2026-09-01', $semester->toArray()['start_date']);
            $this->assertSame('2026-11-20', $leave->toArray()['start_date']);
            $this->assertSame('2026-11-21', $leave->toArray()['end_date']);
            $this->assertSame('2008-05-01', $student->toArray()['birthdate']);
        } finally {
            date_default_timezone_set($prevTz);
        }
    }
}
