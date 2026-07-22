<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentDutyTeacherManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_manager_only_lists_duty_teachers_in_managed_departments(): void
    {
        [$manager, $managedDepartment, $outsideDepartment] = $this->managerWithDepartments();
        $visible = $this->user('visible.duty@example.com', 'duty_teacher');
        $visible->dutyDepartments()->attach([$managedDepartment->id, $outsideDepartment->id]);
        $hidden = $this->user('hidden.duty@example.com', 'duty_teacher');
        $hidden->dutyDepartments()->attach($outsideDepartment->id);

        Sanctum::actingAs($manager);
        $response = $this->getJson('/api/users?role=duty_teacher')->assertOk();

        $response->assertJsonCount(1)
            ->assertJsonPath('0.id', $visible->id)
            ->assertJsonCount(1, '0.duty_departments')
            ->assertJsonPath('0.duty_departments.0.id', $managedDepartment->id);
    }

    public function test_department_manager_can_create_duty_teacher_only_in_managed_departments(): void
    {
        [$manager, $managedDepartment, $outsideDepartment] = $this->managerWithDepartments();
        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/users', [
            'name' => '本系值班老师',
            'email' => 'new.duty@example.com',
            'password' => 'password',
            'role' => 'duty_teacher',
            'department_ids' => [$managedDepartment->id],
        ])->assertCreated();

        $created = User::findOrFail($response->json('id'));
        $this->assertSame([$managedDepartment->id], $created->dutyDepartments()->pluck('departments.id')->all());

        $this->postJson('/api/users', [
            'name' => '越权值班老师',
            'email' => 'outside.duty@example.com',
            'password' => 'password',
            'role' => 'duty_teacher',
            'department_ids' => [$outsideDepartment->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'outside.duty@example.com']);
    }

    public function test_department_manager_updates_only_managed_department_bindings(): void
    {
        [$manager, $managedDepartment, $outsideDepartment, $secondManagedDepartment] = $this->managerWithDepartments(true);
        $dutyTeacher = $this->user('shared.duty@example.com', 'duty_teacher');
        $dutyTeacher->dutyDepartments()->attach([$managedDepartment->id, $outsideDepartment->id]);

        Sanctum::actingAs($manager);
        $this->putJson("/api/users/{$dutyTeacher->id}", [
            'name' => '更新后的值班老师',
            'department_ids' => [$secondManagedDepartment->id],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$secondManagedDepartment->id, $outsideDepartment->id],
            $dutyTeacher->dutyDepartments()->pluck('departments.id')->all()
        );
    }

    public function test_department_manager_cannot_update_duty_teacher_outside_managed_departments(): void
    {
        [$manager, , $outsideDepartment] = $this->managerWithDepartments();
        $dutyTeacher = $this->user('outside.only.duty@example.com', 'duty_teacher');
        $dutyTeacher->dutyDepartments()->attach($outsideDepartment->id);

        Sanctum::actingAs($manager);
        $this->putJson("/api/users/{$dutyTeacher->id}", [
            'name' => '不应更新',
            'department_ids' => [],
        ])->assertForbidden();

        $this->assertNotSame('不应更新', $dutyTeacher->fresh()->name);
    }

    public function test_deleting_shared_duty_teacher_only_detaches_managed_departments(): void
    {
        [$manager, $managedDepartment, $outsideDepartment] = $this->managerWithDepartments();
        $dutyTeacher = $this->user('delete.shared.duty@example.com', 'duty_teacher');
        $dutyTeacher->dutyDepartments()->attach([$managedDepartment->id, $outsideDepartment->id]);

        Sanctum::actingAs($manager);
        $this->deleteJson("/api/users/{$dutyTeacher->id}")
            ->assertOk()
            ->assertJsonPath('message', '已解除该值班老师与当前系部的绑定');

        $this->assertDatabaseHas('users', ['id' => $dutyTeacher->id]);
        $this->assertSame([$outsideDepartment->id], $dutyTeacher->dutyDepartments()->pluck('departments.id')->all());
    }

    private function managerWithDepartments(bool $withSecondManagedDepartment = false): array
    {
        $school = School::create(['name' => '测试学校']);
        $managedDepartment = Department::create(['school_id' => $school->id, 'name' => '艺术系']);
        $outsideDepartment = Department::create(['school_id' => $school->id, 'name' => '设计系']);
        $manager = $this->user('department.manager@example.com', 'department_manager');
        $manager->managedDepartments()->attach($managedDepartment->id);

        $secondManagedDepartment = null;
        if ($withSecondManagedDepartment) {
            $secondManagedDepartment = Department::create(['school_id' => $school->id, 'name' => '美术系']);
            $manager->managedDepartments()->attach($secondManagedDepartment->id);
        }

        return [$manager, $managedDepartment, $outsideDepartment, $secondManagedDepartment];
    }

    private function user(string $email, string $role): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'status' => true,
        ]);
    }
}
