<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\LeaveType;
use App\Models\MobileDevice;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobilePushAndDeviceTest extends TestCase
{
    use RefreshDatabase;

    private function createFixtures(): array
    {
        $school = School::create(['name' => '测试智慧校园']);

        $teacher = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '张班主任',
            'email' => 'teacher@example.com',
            'password' => Hash::make('password123'),
            'role' => 'teacher',
        ]);

        $schoolClass = SchoolClass::create([
            'school_id' => $school->id,
            'name' => '高一(1)班',
            'teacher_id' => $teacher->id,
        ]);

        $studentUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '李同学',
            'email' => 'student@example.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'student_no' => '20260001',
            'gender' => 'male',
        ]);

        $leaveType = LeaveType::create([
            'school_id' => $school->id,
            'name' => '事假',
            'slug' => 'personal_leave',
            'description' => '因事请假',
            'is_active' => true,
            'student_requestable' => true,
        ]);

        return compact('school', 'teacher', 'schoolClass', 'studentUser', 'student', 'leaveType');
    }

    public function test_login_accepts_device_name_and_device_registration_transfers_ownership(): void
    {
        ['teacher' => $teacher, 'studentUser' => $studentUser] = $this->createFixtures();

        $loginRes = $this->postJson('/api/auth/login', [
            'email' => '20260001',
            'password' => 'password123',
            'device_name' => 'iPhone 17 Pro',
        ]);

        $loginRes->assertOk()->assertJsonStructure(['access_token', 'token_type', 'user']);
        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'iPhone 17 Pro',
            'tokenable_id' => $studentUser->id,
        ]);

        Sanctum::actingAs($studentUser);
        $this->postJson('/api/devices/register', [
            'platform' => 'ios',
            'device_identifier' => 'device-uuid-001',
            'push_token' => 'apns_token_sample_123',
            'device_name' => 'iPhone 17 Pro',
            'app_version' => '1.0.0',
            'os_version' => 'iOS 26.1',
        ])->assertOk()->assertJsonPath('device.user_id', $studentUser->id);

        // Switching accounts on the same phone transfers ownership cleanly.
        Sanctum::actingAs($teacher);
        $this->postJson('/api/devices/register', [
            'platform' => 'ios',
            'device_identifier' => 'device-uuid-001',
            'push_token' => 'apns_token_sample_123',
            'device_name' => 'iPhone 17 Pro',
        ])->assertOk()->assertJsonPath('device.user_id', $teacher->id);

        $this->assertEquals(1, MobileDevice::where('device_identifier', 'device-uuid-001')->count());
        $this->assertDatabaseHas('mobile_devices', [
            'device_identifier' => 'device-uuid-001',
            'user_id' => $teacher->id,
        ]);

        // Re-registering without push_token (e.g. cold-start race) must NOT wipe existing push_token.
        $this->postJson('/api/devices/register', [
            'platform' => 'ios',
            'device_identifier' => 'device-uuid-001',
            'push_token' => null,
            'device_name' => 'iPhone 17 Pro',
        ])->assertOk()->assertJsonPath('device.push_token', 'apns_token_sample_123');

        // Unregister removes current device cleanly.
        $this->postJson('/api/devices/unregister', [
            'platform' => 'ios',
            'device_identifier' => 'device-uuid-001',
        ])->assertOk()->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('mobile_devices', [
            'device_identifier' => 'device-uuid-001',
        ]);
    }

    public function test_leave_lifecycle_creates_in_app_notifications_and_gracefully_skips_push_without_cert(): void
    {
        ['teacher' => $teacher, 'studentUser' => $studentUser, 'leaveType' => $leaveType] = $this->createFixtures();

        // Register devices for teacher and student without APNs credentials configured.
        MobileDevice::create([
            'user_id' => $teacher->id,
            'platform' => 'ios',
            'push_provider' => 'apns',
            'device_identifier' => 'teacher-iphone',
            'push_token' => 'dummy_teacher_token',
        ]);

        MobileDevice::create([
            'user_id' => $studentUser->id,
            'platform' => 'ios',
            'push_provider' => 'apns',
            'device_identifier' => 'student-iphone',
            'push_token' => 'dummy_student_token',
        ]);

        // 1. Student submits leave request -> teacher gets notification.
        Sanctum::actingAs($studentUser);
        $createRes = $this->postJson('/api/leave-requests', [
            'type' => $leaveType->slug,
            'start_date' => '2026-11-25',
            'end_date' => '2026-11-25',
            'reason' => '家里有事请假一天',
        ]);
        $createRes->assertStatus(201);
        $leaveId = $createRes->json('id');

        Sanctum::actingAs($teacher);
        $teacherNotifs = $this->getJson('/api/notifications');
        $teacherNotifs->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', 'leave_submitted')
            ->assertJsonPath('data.0.data.route', 'leave_approvals');

        // Mark teacher notification as read.
        $notifId = $teacherNotifs->json('data.0.id');
        $this->postJson("/api/notifications/{$notifId}/read")->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('unread_count', 0);

        // 2. Teacher approves leave request -> student gets notification.
        $this->postJson("/api/leave-requests/{$leaveId}/approve")->assertOk();

        Sanctum::actingAs($studentUser);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', 'leave_approved')
            ->assertJsonPath('data.0.data.route', 'leave_history');

        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('unread_count', 0);

        // Ensure push status gracefully recorded without crashing when APNs certs are absent.
        $studentNotif = AppNotification::where('user_id', $studentUser->id)->first();
        $this->assertNotNull($studentNotif);
        $this->assertContains($studentNotif->push_status, ['queued', 'skipped_no_credentials']);
    }
}
