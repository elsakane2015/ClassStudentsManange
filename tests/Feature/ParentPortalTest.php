<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\EveningStudySession;
use App\Models\EveningStudyStatus;
use App\Models\LeaveType;
use App\Models\RollCall;
use App\Models\RollCallRecord;
use App\Models\RollCallType;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ParentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    use RefreshDatabase;

    private function createCampusFixtures(): array
    {
        $school = School::create(['name' => '示范智慧中学']);

        $admin = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '系统管理员',
            'email' => 'admin@school.test',
            'password' => Hash::make('admin123'),
            'role' => 'system_admin',
            'status' => true,
        ]);

        $teacher = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '王班主任',
            'email' => 'teacher@school.test',
            'password' => Hash::make('teacher123'),
            'role' => 'teacher',
            'status' => true,
        ]);

        $schoolClass = SchoolClass::create([
            'school_id' => $school->id,
            'name' => '高一(1)班',
            'teacher_id' => $teacher->id,
            'is_graduated' => false,
        ]);

        $leaveType = LeaveType::create([
            'school_id' => $school->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'description' => '因病请假',
            'is_active' => true,
            'student_requestable' => true,
            'color' => 'purple',
        ]);

        $absentLeaveType = LeaveType::create([
            'school_id' => $school->id,
            'name' => '旷课',
            'slug' => 'absent',
            'description' => '无故缺勤',
            'is_active' => true,
            'student_requestable' => false,
            'color' => 'red',
        ]);

        SystemSetting::set('attendance_periods', json_encode([
            ['id' => 1, 'name' => '第1节', 'type' => 'regular', 'order' => 1, 'scene' => 'regular', 'audience_scope' => 'all', 'counts_in_day_stats' => true, 'is_active' => true],
            ['id' => 2, 'name' => '第2节', 'type' => 'regular', 'order' => 2, 'scene' => 'regular', 'audience_scope' => 'all', 'counts_in_day_stats' => true, 'is_active' => true],
            ['id' => 10, 'name' => '晚自习一', 'type' => 'special', 'order' => 10, 'scene' => 'evening_study', 'audience_scope' => 'boarding', 'counts_in_day_stats' => false, 'is_active' => true],
        ], JSON_UNESCAPED_UNICODE));

        return compact('school', 'admin', 'teacher', 'schoolClass', 'leaveType', 'absentLeaveType');
    }

    public function test_parent_emails_sync_multiple_parents_and_multi_child_families_with_default_password(): void
    {
        ['admin' => $admin, 'teacher' => $teacher, 'schoolClass' => $schoolClass] = $this->createCampusFixtures();

        // Admin configures unified parent default password
        Sanctum::actingAs($admin);
        $this->postJson('/api/settings', [
            'settings' => [
                ['key' => 'parent_default_password', 'value' => 'parent888'],
            ],
        ])->assertOk();

        // Teacher creates child 1 with two comma-separated parent emails (mixed case)
        Sanctum::actingAs($teacher);
        $createRes = $this->postJson('/api/students', [
            'name' => '张大宝',
            'student_no' => '2026001',
            'gender' => 'male',
            'is_boarding' => true,
            'parent_contact' => '13800000001',
            'parent_email' => 'Dad@Family.Test, mom@family.test',
            'class_id' => $schoolClass->id,
            'email' => 'dabao@school.test',
            'password' => 'student123',
        ]);
        $createRes->assertStatus(201);
        $child1Id = $createRes->json('id');

        $dad = User::where('email', 'dad@family.test')->first();
        $mom = User::where('email', 'mom@family.test')->first();
        $this->assertNotNull($dad);
        $this->assertNotNull($mom);
        $this->assertSame('parent', $dad->role);
        $this->assertSame('parent', $mom->role);

        // Dad can sign in case-insensitively with system default password
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'DAD@family.test',
            'password' => 'parent888',
        ]);
        $loginRes->assertOk()->assertJsonPath('user.role', 'parent');

        // Dad changes his own password
        Sanctum::actingAs($dad);
        $this->postJson('/api/user/change-password', [
            'current_password' => 'parent888',
            'new_password' => 'dadNewPass99',
            'new_password_confirmation' => 'dadNewPass99',
        ])->assertOk();

        // Teacher creates child 2 sharing dad's email -> dad's custom password must NOT be overwritten
        Sanctum::actingAs($teacher);
        $child2Res = $this->postJson('/api/students', [
            'name' => '张二宝',
            'student_no' => '2026002',
            'gender' => 'female',
            'is_boarding' => false,
            'parent_email' => 'dad@family.test',
            'class_id' => $schoolClass->id,
            'email' => 'erbao@school.test',
            'password' => 'student123',
        ]);
        $child2Res->assertStatus(201);
        $this->assertTrue(Hash::check('dadNewPass99', $dad->fresh()->password));

        // Dad sees both children in /me and /parent/children
        Sanctum::actingAs($dad);
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('has_parent_children', true)
            ->assertJsonCount(2, 'parent_children');

        $this->getJson('/api/parent/children')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Removing mom from child 1 deactivates mom since she has no other children
        Sanctum::actingAs($teacher);
        $this->putJson("/api/students/{$child1Id}", [
            'parent_email' => 'dad@family.test',
        ])->assertOk();

        $this->assertFalse((bool) $mom->fresh()->status);
        $this->postJson('/api/auth/login', [
            'email' => 'mom@family.test',
            'password' => 'parent888',
        ])->assertStatus(403);
    }

    public function test_staff_email_can_be_shared_as_parent_while_student_email_collision_is_blocked(): void
    {
        ['teacher' => $teacher, 'schoolClass' => $schoolClass] = $this->createCampusFixtures();

        Sanctum::actingAs($teacher);

        // Collision with student's own email is rejected
        $this->postJson('/api/students', [
            'name' => '冲突同学',
            'student_no' => '2026009',
            'gender' => 'male',
            'parent_email' => 'self@school.test',
            'class_id' => $schoolClass->id,
            'email' => 'self@school.test',
            'password' => 'student123',
        ])->assertStatus(422);

        // Staff member (teacher) email IS permitted as parent_email (Scheme B)
        $childRes = $this->postJson('/api/students', [
            'name' => '王小宝',
            'student_no' => '2026010',
            'gender' => 'male',
            'parent_email' => $teacher->email,
            'class_id' => $schoolClass->id,
            'email' => 'xiaobao@school.test',
            'password' => 'student123',
        ]);
        $childRes->assertStatus(201);
        $childId = $childRes->json('id');

        // Teacher retains teacher role & password, while gaining parent_children
        $teacher->refresh();
        $this->assertSame('teacher', $teacher->role);
        $this->assertTrue(Hash::check('teacher123', $teacher->password));

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('role', 'teacher')
            ->assertJsonPath('has_parent_children', true)
            ->assertJsonPath('parent_children.0.name', '王小宝');

        $this->getJson("/api/parent/children/{$childId}/overview")
            ->assertOk()
            ->assertJsonPath('child.name', '王小宝');

        // Teacher cannot reset a staff account's password via the student parent-reset endpoint
        $this->postJson("/api/students/{$childId}/parents/{$teacher->id}/reset-password", [
            'password' => 'hacked999',
        ])->assertStatus(422);
    }

    public function test_parent_role_is_strictly_isolated_from_non_parent_endpoints_and_other_students(): void
    {
        ['teacher' => $teacher, 'schoolClass' => $schoolClass] = $this->createCampusFixtures();

        Sanctum::actingAs($teacher);
        $s1 = $this->postJson('/api/students', [
            'name' => '孩子甲',
            'student_no' => '2026101',
            'gender' => 'male',
            'parent_email' => 'p1@family.test',
            'class_id' => $schoolClass->id,
            'email' => 's1@school.test',
            'password' => 'student123',
        ])->assertStatus(201)->json('id');

        $s2 = $this->postJson('/api/students', [
            'name' => '孩子乙',
            'student_no' => '2026102',
            'gender' => 'female',
            'parent_email' => 'p2@family.test',
            'class_id' => $schoolClass->id,
            'email' => 's2@school.test',
            'password' => 'student123',
        ])->assertStatus(201)->json('id');

        $p1 = User::where('email', 'p1@family.test')->firstOrFail();
        Sanctum::actingAs($p1);

        // Parent can access their own child
        $this->getJson("/api/parent/children/{$s1}/overview")->assertOk();
        $this->getJson("/api/parent/children/{$s1}/calendar")->assertOk();
        $this->getJson("/api/parent/children/{$s1}/leave-requests")->assertOk();
        $this->getJson("/api/parent/children/{$s1}/records")->assertOk();

        // Parent CANNOT access another student
        $this->getJson("/api/parent/children/{$s2}/overview")->assertStatus(403);
        $this->getJson("/api/parent/children/{$s2}/records")->assertStatus(403);

        // Parent CANNOT access teacher/student/admin routes
        $this->getJson('/api/students')->assertStatus(403);
        $this->getJson('/api/leave-requests')->assertStatus(403);
        $this->getJson('/api/attendance/stats')->assertStatus(403);
        $this->getJson("/api/attendance/student-records?student_id={$s2}")->assertStatus(403);
        $this->getJson('/api/settings')->assertStatus(403);
    }

    public function test_full_notification_pipeline_for_leave_attendance_rollcall_and_evening_study(): void
    {
        ['school' => $school, 'admin' => $admin, 'teacher' => $teacher, 'schoolClass' => $schoolClass, 'leaveType' => $leaveType, 'absentLeaveType' => $absentLeaveType] = $this->createCampusFixtures();

        Sanctum::actingAs($teacher);
        $studentId = $this->postJson('/api/students', [
            'name' => '李小明',
            'student_no' => '2026201',
            'gender' => 'male',
            'is_boarding' => true,
            'parent_email' => 'dad.li@family.test, mom.li@family.test',
            'class_id' => $schoolClass->id,
            'email' => 'xiaoming@school.test',
            'password' => 'student123',
        ])->assertStatus(201)->json('id');

        $student = Student::with('user')->findOrFail($studentId);
        $dad = User::where('email', 'dad.li@family.test')->firstOrFail();
        $mom = User::where('email', 'mom.li@family.test')->firstOrFail();

        // 1. Student submits leave request -> both parents receive parent_leave_submitted
        Sanctum::actingAs($student->user);
        $leaveRes = $this->postJson('/api/leave-requests', [
            'type' => $leaveType->slug,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => '发烧去医院',
        ])->assertStatus(201);
        $leaveId = $leaveRes->json('id');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $dad->id,
            'type' => 'parent_leave_submitted',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $mom->id,
            'type' => 'parent_leave_submitted',
        ]);

        // 2. Teacher approves leave request -> both parents receive parent_leave_approved
        Sanctum::actingAs($teacher);
        $this->postJson("/api/leave-requests/{$leaveId}/approve")->assertOk();
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $dad->id,
            'type' => 'parent_leave_approved',
        ]);

        // 3. Manual attendance marking (late) -> parent receives parent_attendance_marked
        $this->postJson('/api/attendance/bulk', [
            'date' => now()->addDay()->toDateString(),
            'records' => [
                [
                    'student_id' => $student->id,
                    'status' => 'late',
                    'details' => ['text' => '早读迟到10分钟'],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $dad->id,
            'type' => 'parent_attendance_marked',
        ]);

        // 4. Multi-period roll call absence -> aggregated into ONE parent notification (not 2)
        $beforeRollCallCount = AppNotification::where('user_id', $dad->id)
            ->where('type', 'parent_attendance_marked')
            ->count();

        $rollCallType = RollCallType::create([
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'name' => '上午两节联点',
            'leave_type_id' => $absentLeaveType->id,
            'period_ids' => [1, 2],
            'created_by' => $teacher->id,
            'is_active' => true,
        ]);
        $rollCall = RollCall::create([
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'roll_call_type_id' => $rollCallType->id,
            'roll_call_time' => now()->addDays(2),
            'created_by' => $teacher->id,
            'status' => 'in_progress',
            'total_students' => 1,
        ]);
        RollCallRecord::create([
            'roll_call_id' => $rollCall->id,
            'student_id' => $student->id,
            'status' => 'pending',
        ]);

        $this->postJson("/api/roll-calls/{$rollCall->id}/complete")->assertOk();

        $afterRollCallCount = AppNotification::where('user_id', $dad->id)
            ->where('type', 'parent_attendance_marked')
            ->count();
        $this->assertSame($beforeRollCallCount + 1, $afterRollCallCount);

        // 5. Evening study abnormal mark on session complete -> parent receives parent_evening_study_alert
        $presentStatus = EveningStudyStatus::create([
            'school_id' => $school->id,
            'name' => '在班',
            'color' => 'green',
            'base_status' => 'present',
            'is_default' => true,
            'student_requestable' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $absentStatus = EveningStudyStatus::create([
            'school_id' => $school->id,
            'name' => '未到',
            'color' => 'red',
            'base_status' => 'absent',
            'is_default' => false,
            'student_requestable' => false,
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $suspensionStatus = EveningStudyStatus::create([
            'school_id' => $school->id,
            'name' => '暂停住宿',
            'color' => 'purple',
            'base_status' => 'excused',
            'is_default' => false,
            'student_requestable' => false,
            'is_active' => true,
            'sort_order' => 3,
        ]);
        SystemSetting::set('boarding_suspension_status_id', (string) $suspensionStatus->id);

        Sanctum::actingAs($admin);
        $sessionRes = $this->postJson('/api/evening-study/sessions', [
            'class_id' => $schoolClass->id,
            'period_id' => 10,
            'date' => now()->addDays(3)->toDateString(),
        ])->assertSuccessful();

        $sessionId = $sessionRes->json('session.id');
        $recordId = $sessionRes->json('records.0.id');

        $this->postJson("/api/evening-study/sessions/{$sessionId}/complete", [
            'records' => [
                [
                    'id' => $recordId,
                    'status_id' => $absentStatus->id,
                    'note' => '点名未在教室',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $dad->id,
            'type' => 'parent_evening_study_alert',
        ]);
    }

    public function test_teacher_reset_parent_password_and_email_code_self_service_reset(): void
    {
        ['teacher' => $teacher, 'schoolClass' => $schoolClass] = $this->createCampusFixtures();

        Sanctum::actingAs($teacher);
        $studentId = $this->postJson('/api/students', [
            'name' => '赵同学',
            'student_no' => '2026301',
            'gender' => 'female',
            'parent_email' => 'parent.zhao@family.test',
            'class_id' => $schoolClass->id,
            'email' => 'zhao@school.test',
            'password' => 'student123',
        ])->assertStatus(201)->json('id');

        // Teacher inspects parent accounts and resets password to a custom value
        $parentsRes = $this->getJson("/api/students/{$studentId}/parents")->assertOk();
        $parentUserId = $parentsRes->json('data.0.id');
        $this->assertTrue((bool) $parentsRes->json('data.0.can_reset_password'));

        $this->postJson("/api/students/{$studentId}/parents/{$parentUserId}/reset-password", [
            'password' => 'resetByTeacher1',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'parent.zhao@family.test',
            'password' => 'resetByTeacher1',
        ])->assertOk();

        // Self-service email code password reset (Option C) using array mailer
        config(['mail.default' => 'array']);
        $this->postJson('/api/auth/password-reset/send-code', [
            'email' => 'parent.zhao@family.test',
        ])->assertOk();

        $codeRow = DB::table('password_reset_codes')
            ->where('email', 'parent.zhao@family.test')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($codeRow);

        // Replace code_hash with a known code to verify confirm flow end-to-end
        DB::table('password_reset_codes')
            ->where('id', $codeRow->id)
            ->update(['code_hash' => Hash::make('654321')]);

        // Wrong code fails
        $this->postJson('/api/auth/password-reset/confirm', [
            'email' => 'parent.zhao@family.test',
            'code' => '000000',
            'password' => 'selfReset999',
            'password_confirmation' => 'selfReset999',
        ])->assertStatus(422);

        // Correct code succeeds and enables login with new password
        $this->postJson('/api/auth/password-reset/confirm', [
            'email' => 'parent.zhao@family.test',
            'code' => '654321',
            'password' => 'selfReset999',
            'password_confirmation' => 'selfReset999',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'parent.zhao@family.test',
            'password' => 'selfReset999',
        ])->assertOk();
    }

    public function test_graduated_children_blocking_and_artisan_backfill_command(): void
    {
        ['school' => $school, 'teacher' => $teacher, 'schoolClass' => $activeClass] = $this->createCampusFixtures();

        $graduatedClass = SchoolClass::create([
            'school_id' => $school->id,
            'name' => '2022届(1)班',
            'teacher_id' => $teacher->id,
            'is_graduated' => true,
        ]);

        // Directly insert student bypassing API to simulate legacy data before backfill
        $legacyStudentUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '毕业同学',
            'email' => 'grad@school.test',
            'password' => Hash::make('student123'),
            'role' => 'student',
            'status' => true,
        ]);
        Student::create([
            'user_id' => $legacyStudentUser->id,
            'school_id' => $school->id,
            'class_id' => $graduatedClass->id,
            'student_no' => '2022001',
            'gender' => 'male',
            'parent_email' => 'legacy.parent@family.test',
        ]);

        $this->artisan('parents:sync-accounts')->assertExitCode(0);
        $this->assertDatabaseHas('users', [
            'email' => 'legacy.parent@family.test',
            'role' => 'parent',
        ]);

        // All linked children graduated -> login blocked with 403
        $this->postJson('/api/auth/login', [
            'email' => 'legacy.parent@family.test',
            'password' => '123456',
        ])->assertStatus(403);

        // Once the same parent also has a child in an active class -> login succeeds
        Sanctum::actingAs($teacher);
        $this->postJson('/api/students', [
            'name' => '在读妹妹',
            'student_no' => '2026999',
            'gender' => 'female',
            'parent_email' => 'legacy.parent@family.test',
            'class_id' => $activeClass->id,
            'email' => 'sister@school.test',
            'password' => 'student123',
        ])->assertStatus(201);

        $this->postJson('/api/auth/login', [
            'email' => 'legacy.parent@family.test',
            'password' => '123456',
        ])->assertOk();
    }
}
