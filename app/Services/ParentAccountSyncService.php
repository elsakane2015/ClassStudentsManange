<?php

namespace App\Services;

use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ParentEmailList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParentAccountSyncService
{
    public const DEFAULT_PASSWORD_SETTING_KEY = 'parent_default_password';

    public const FALLBACK_DEFAULT_PASSWORD = '123456';

    public function defaultPassword(): string
    {
        try {
            if (Schema::hasTable('system_settings')) {
                $configured = trim((string) SystemSetting::get(self::DEFAULT_PASSWORD_SETTING_KEY, ''));
                if (mb_strlen($configured) >= 6) {
                    return $configured;
                }
            }
        } catch (\Throwable) {
            // Fall through to default password
        }

        return self::FALLBACK_DEFAULT_PASSWORD;
    }

    /**
     * Validate that parent emails do not collide with any student login email.
     * Staff/teacher emails are explicitly permitted (faculty who are also parents share their existing account).
     *
     * @throws ValidationException
     */
    public function assertNoStudentEmailCollision(
        ?string $rawParentEmails,
        ?string $studentOwnEmail = null,
        string $errorKey = 'parent_email'
    ): void {
        $emails = array_map(
            fn (string $email) => mb_strtolower(trim($email)),
            ParentEmailList::parse($rawParentEmails)
        );
        if ($emails === []) {
            return;
        }

        $normalizedStudentEmail = $studentOwnEmail ? mb_strtolower(trim($studentOwnEmail)) : null;
        if ($normalizedStudentEmail && in_array($normalizedStudentEmail, $emails, true)) {
            throw ValidationException::withMessages([
                $errorKey => "家长邮箱“{$normalizedStudentEmail}”不能与学生本人登录邮箱相同。",
            ]);
        }

        $conflictingStudentEmail = User::where('role', 'student')
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->value('email');

        if ($conflictingStudentEmail) {
            throw ValidationException::withMessages([
                $errorKey => "家长邮箱“{$conflictingStudentEmail}”已被学生账号使用，请填写家长本人邮箱。",
            ]);
        }
    }

    /**
     * Synchronize `students.parent_email` with `users` (role='parent' or existing staff account)
     * and the `parent_student` pivot table.
     *
     * @return array{synced_user_ids: int[], created_user_ids: int[], deactivated_user_ids: int[]}
     */
    public function syncForStudent(Student $student, ?string $initialPassword = null): array
    {
        if (! Schema::hasTable('parent_student')) {
            return ['synced_user_ids' => [], 'created_user_ids' => [], 'deactivated_user_ids' => []];
        }

        $student->loadMissing('user:id,name,email');
        $parsedEmails = ParentEmailList::parse($student->parent_email);
        $normalizedEmails = array_values(array_unique(array_map(
            fn (string $email) => mb_strtolower(trim($email)),
            $parsedEmails
        )));

        $passwordToUse = ($initialPassword !== null && mb_strlen(trim($initialPassword)) >= 6)
            ? trim($initialPassword)
            : $this->defaultPassword();

        $studentName = trim((string) ($student->user?->name ?? ''));
        $defaultParentName = $studentName !== '' ? "{$studentName}家长" : '学生家长';

        $previousParentIds = $student->parents()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $targetParentIds = [];
        $createdUserIds = [];

        foreach ($normalizedEmails as $emailLower) {
            $existing = User::whereRaw('LOWER(email) = ?', [$emailLower])->first();

            if ($existing) {
                // Defensive skip: never bind a student account as a parent.
                if ($existing->role === 'student') {
                    continue;
                }

                // Re-enable previously detached parent-only accounts without touching their password.
                if ($existing->role === 'parent' && ! $existing->status) {
                    $existing->status = true;
                    $existing->save();
                }

                $targetParentIds[] = (int) $existing->id;
                continue;
            }

            $parentUser = User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $defaultParentName,
                'email' => $emailLower,
                'password' => Hash::make($passwordToUse),
                'role' => 'parent',
                'status' => true,
            ]);

            $targetParentIds[] = (int) $parentUser->id;
            $createdUserIds[] = (int) $parentUser->id;
        }

        $targetParentIds = array_values(array_unique($targetParentIds));
        $student->parents()->sync($targetParentIds);

        $removedParentIds = array_values(array_diff($previousParentIds, $targetParentIds));
        $deactivatedUserIds = $this->cleanupOrphanedParents($removedParentIds);

        return [
            'synced_user_ids' => $targetParentIds,
            'created_user_ids' => $createdUserIds,
            'deactivated_user_ids' => $deactivatedUserIds,
        ];
    }

    /**
     * Detach all parent links before deleting a student and deactivate orphaned parent-only accounts.
     */
    public function detachStudentAndCleanup(Student $student): array
    {
        if (! Schema::hasTable('parent_student')) {
            return [];
        }

        $previousParentIds = $student->parents()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $student->parents()->detach();

        return $this->cleanupOrphanedParents($previousParentIds);
    }

    /**
     * Backfill all students who currently have `parent_email` populated.
     */
    public function syncAllStudents(): array
    {
        $studentCount = 0;
        $createdCount = 0;

        Student::with('user:id,name,email')
            ->whereNotNull('parent_email')
            ->where('parent_email', '!=', '')
            ->chunkById(100, function ($students) use (&$studentCount, &$createdCount) {
                foreach ($students as $student) {
                    $result = $this->syncForStudent($student);
                    $studentCount++;
                    $createdCount += count($result['created_user_ids']);
                }
            });

        return [
            'students_processed' => $studentCount,
            'parent_accounts_created' => $createdCount,
        ];
    }

    /**
     * Reset password for a dedicated parent account (`role === 'parent'`).
     * Staff accounts that also act as parents cannot be reset here to prevent privilege escalation.
     */
    public function resetParentPassword(User $parentUser, ?string $newPassword = null): string
    {
        if ($parentUser->role !== 'parent') {
            abort(422, '该邮箱为本校教职工账号，不能在此重置密码，请联系管理员在人员管理中处理或由本人找回密码。');
        }

        $resolvedPassword = ($newPassword !== null && trim($newPassword) !== '')
            ? trim($newPassword)
            : $this->defaultPassword();

        if (mb_strlen($resolvedPassword) < 6) {
            throw ValidationException::withMessages([
                'password' => '密码长度不能少于 6 位。',
            ]);
        }

        $parentUser->password = Hash::make($resolvedPassword);
        $parentUser->status = true;
        $parentUser->save();

        // Revoke old tokens so the parent signs in cleanly with the new password.
        $parentUser->tokens()->delete();

        return $resolvedPassword;
    }

    /**
     * Format parent account summaries for teacher/admin management views.
     */
    public function summarizeParentsForStudent(Student $student): array
    {
        if (! Schema::hasTable('parent_student')) {
            return [];
        }

        $parents = $student->parents()
            ->withCount(['mobileDevices as push_ready_devices_count' => function ($query) {
                $query->where('push_enabled', true)
                    ->whereNotNull('push_token')
                    ->where('push_token', '!=', '');
            }])
            ->withCount('mobileDevices')
            ->withCount('children')
            ->get();

        return $parents->map(function (User $parent) {
            $isStaff = $parent->role !== 'parent';

            return [
                'id' => $parent->id,
                'name' => $parent->name,
                'email' => $parent->email,
                'role' => $parent->role,
                'is_staff_account' => $isStaff,
                'status' => (bool) $parent->status,
                'children_count' => (int) ($parent->children_count ?? 1),
                'device_count' => (int) ($parent->mobile_devices_count ?? 0),
                'push_ready' => ((int) ($parent->push_ready_devices_count ?? 0)) > 0,
                'can_reset_password' => ! $isStaff,
            ];
        })->values()->all();
    }

    /**
     * Deactivate parent-only accounts that no longer link to any student.
     *
     * @param  int[]  $candidateParentIds
     * @return int[]
     */
    private function cleanupOrphanedParents(array $candidateParentIds): array
    {
        if ($candidateParentIds === []) {
            return [];
        }

        $deactivated = [];
        $candidates = User::whereIn('id', $candidateParentIds)
            ->where('role', 'parent')
            ->get();

        foreach ($candidates as $parent) {
            if ($parent->children()->exists()) {
                continue;
            }

            $parent->status = false;
            $parent->save();
            $parent->tokens()->delete();
            if (Schema::hasTable('mobile_devices')) {
                $parent->mobileDevices()->delete();
            }
            $deactivated[] = (int) $parent->id;
        }

        return $deactivated;
    }
}
