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

    public function test_formats_attendance_period_names_in_natural_school_order(): void
    {
        $names = ['第1节', '第2节', '第3节', '第4节', '第5节', '第6节', '早读', '早操', '晚操'];
        $this->assertSame(
            '早读、早操、第1-6节、晚操',
            AttendanceController::formatAttendancePeriodNames($names)
        );

        $this->assertSame(
            '早读、第1-3,5节、晚操',
            AttendanceController::formatAttendancePeriodNames(['第2节', '第5节', '第1节', '晚操', '第3节', '早读'])
        );
    }

    public function test_formats_manual_marked_calendar_option_compactly(): void
    {
        $periodMap = [
            1 => ['id' => 1, 'name' => '早读'],
            2 => ['id' => 2, 'name' => '早操'],
            3 => ['id' => 3, 'name' => '第1节'],
            4 => ['id' => 4, 'name' => '第2节'],
            5 => ['id' => 5, 'name' => '第3节'],
            6 => ['id' => 6, 'name' => '第4节'],
            7 => ['id' => 7, 'name' => '第5节'],
            8 => ['id' => 8, 'name' => '第6节'],
            9 => ['id' => 9, 'name' => '晚操'],
        ];

        // 1. 手工标记带 text（技能训练）+ period_names（9节未合并）
        $manualWithText = AttendanceController::formatCompactCalendarOption([
            'text' => '技能训练',
            'period_names' => ['早读', '早操', '第1节', '第2节', '第3节', '第4节', '第5节', '第6节', '晚操'],
        ], $periodMap);
        $this->assertSame('早读、早操、第1-6节、晚操 (9节) · 技能训练', $manualWithText['option']);
        $this->assertSame('技能训练', $manualWithText['note']);

        // 2. 仅有 period_ids 自定义节次（无 text、无 option_label）
        $periodIdsOnly = AttendanceController::formatCompactCalendarOption([
            'period_ids' => [1, 2, 3, 4, 5],
            'is_custom' => true,
        ], $periodMap);
        $this->assertSame('早读、早操、第1-3节 (5节)', $periodIdsOnly['option']);
        $this->assertNull($periodIdsOnly['note']);

        // 3. 历史平铺的 option_label
        $legacyOptionLabel = AttendanceController::formatCompactCalendarOption([
            'option_label' => '早读、早操、第1节、第2节、第3节',
        ], $periodMap);
        $this->assertSame('早读、早操、第1-3节 (5节)', $legacyOptionLabel['option']);

        // 4. 仅文本无节次
        $textOnly = AttendanceController::formatCompactCalendarOption([
            'text' => '技能训练',
        ], $periodMap);
        $this->assertSame('技能训练', $textOnly['option']);
        $this->assertNull($textOnly['note']);
    }

    public function test_resolves_details_query_mode_for_leave_types_and_legacy_slugs(): void
    {
        // 标准 Web / 新移动端传参：status=leave + leave_type_id
        $this->assertSame(
            ['mode' => 'leave_type', 'status' => 'leave', 'leave_type_id' => 2],
            AttendanceController::resolveDetailsQueryMode('leave', 2)
        );

        // 兼容老版客户端误传 status=sick/personal/special/menstrual 等 slug
        $this->assertSame(
            ['mode' => 'leave_type', 'status' => 'leave', 'leave_type_id' => 2],
            AttendanceController::resolveDetailsQueryMode('sick', 2)
        );
        $this->assertSame(
            ['mode' => 'leave_type', 'status' => 'leave', 'leave_type_id' => 5],
            AttendanceController::resolveDetailsQueryMode('special', 5)
        );

        // 旷课/迟到/早退直查状态，避免额外叠加 leave_type_id 过滤漏记录
        $this->assertSame(
            ['mode' => 'absent', 'status' => 'absent', 'leave_type_id' => null],
            AttendanceController::resolveDetailsQueryMode('absent', 9)
        );
        $this->assertSame(
            ['mode' => 'direct_status', 'status' => 'late', 'leave_type_id' => null],
            AttendanceController::resolveDetailsQueryMode('late', 10)
        );
        $this->assertSame(
            ['mode' => 'direct_status', 'status' => 'early_leave', 'leave_type_id' => null],
            AttendanceController::resolveDetailsQueryMode('early_leave', 11)
        );
    }

    public function test_resolves_calendar_summary_note_for_self_applied_and_manual_records(): void
    {
        // 1. 学生自主请假（如特殊假、病假）：事由存于 reason 字段
        $selfSpecial = new AttendanceRecord([
            'is_self_applied' => true,
            'reason' => '参加省技能大赛集训',
            'note' => null,
        ]);
        $this->assertSame(
            '参加省技能大赛集训',
            AttendanceController::resolveCalendarSummaryNote($selfSpecial, [], '早读、早操、第1-6节、晚操 (9节)')
        );

        // 2. 手工标记带 details.text
        $manual = new AttendanceRecord(['reason' => null, 'note' => null]);
        $this->assertSame(
            '技能训练',
            AttendanceController::resolveCalendarSummaryNote($manual, ['text' => '技能训练'], '早读、早操、第1-6节、晚操 (9节) · 技能训练', '技能训练')
        );

        // 3. 仅文本无节次时，不与 optionLabel 重复输出
        $textOnly = new AttendanceRecord(['reason' => null, 'note' => '技能训练']);
        $this->assertNull(
            AttendanceController::resolveCalendarSummaryNote($textOnly, ['text' => '技能训练'], '技能训练', null)
        );
    }

    public function test_summarizes_multi_day_multi_period_student_details_without_duplicate_rows(): void
    {
        $periodMap = [
            1 => ['id' => 1, 'name' => '早读'],
            2 => ['id' => 2, 'name' => '早操'],
            3 => ['id' => 3, 'name' => '第1节'],
            4 => ['id' => 4, 'name' => '第2节'],
            5 => ['id' => 5, 'name' => '第3节'],
            6 => ['id' => 6, 'name' => '第4节'],
            7 => ['id' => 7, 'name' => '第5节'],
            8 => ['id' => 8, 'name' => '第6节'],
            9 => ['id' => 9, 'name' => '晚操'],
        ];

        // 模拟学生跨 5 天每天 9 节自主申请（共 45 条按单节存的记录）
        $records = [];
        $id = 1;
        foreach (['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27'] as $date) {
            for ($pid = 1; $pid <= 9; $pid++) {
                $rec = new AttendanceRecord();
                $rec->setRawAttributes([
                    'id' => $id++,
                    'date' => $date,
                    'period_id' => $pid,
                    'status' => 'leave',
                    'leave_type_id' => 4,
                    'leave_batch_id' => 'batch-qyx-5days',
                    'is_self_applied' => true,
                    'source_type' => 'self_applied',
                    'scene' => 'regular',
                    'reason' => '技能训练',
                    'details' => json_encode(['display_label' => '全天']),
                ]);
                $records[] = $rec;
            }
        }

        $summary = AttendanceController::summarizeStudentDetailRecords($records, 'week', $periodMap);
        $this->assertSame('5天 · 45节', $summary['detail']);
        $this->assertSame(5, $summary['day_count']);
        $this->assertSame(45, $summary['period_count']);
        $this->assertCount(5, $summary['records']);
        $this->assertSame('2026-11-23', $summary['records'][0]['date']);
        $this->assertSame('2026-11-27', $summary['records'][4]['date']);
        $this->assertSame('申请：早读、早操、第1-6节、晚操 (9节)', $summary['records'][0]['detail']);
    }

    public function test_allows_class_admin_to_access_calendar_summary(): void
    {
        $teacher = (object) ['role' => 'teacher', 'student' => null];
        $classAdmin = (object) [
            'role' => 'student',
            'student' => (object) ['is_class_admin' => true, 'class_id' => 12],
        ];
        $regularStudent = (object) [
            'role' => 'student',
            'student' => (object) ['is_class_admin' => false, 'class_id' => 12],
        ];
        $parent = (object) ['role' => 'parent', 'student' => null];

        $this->assertTrue(AttendanceController::canAccessCalendarSummary($teacher));
        $this->assertTrue(AttendanceController::canAccessCalendarSummary($classAdmin));
        $this->assertFalse(AttendanceController::canAccessCalendarSummary($regularStudent));
        $this->assertFalse(AttendanceController::canAccessCalendarSummary($parent));
        $this->assertFalse(AttendanceController::canAccessCalendarSummary(null));
    }
}
