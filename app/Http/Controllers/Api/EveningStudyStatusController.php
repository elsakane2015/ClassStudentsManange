<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\EveningStudyStatus;
use App\Models\School;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EveningStudyStatusController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = $this->schoolId($request);
        $query = EveningStudyStatus::where('school_id', $schoolId)
            ->orderBy('sort_order')
            ->orderBy('id');

        if (!in_array($request->user()->role, ['system_admin', 'school_admin'], true)) {
            $query->where('is_active', true);
        }
        if ($request->boolean('student_requestable')) {
            $query->where('student_requestable', true)->where('is_active', true);
        }

        return response()->json([
            'statuses' => $query->get(),
            'boarding_suspension_status_id' => (int) SystemSetting::get('boarding_suspension_status_id', 0),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeSettings($request);
        $schoolId = $this->schoolId($request);
        $data = $this->validated($request, $schoolId);

        $status = DB::transaction(function () use ($data, $schoolId) {
            if ($data['is_default']) {
                EveningStudyStatus::where('school_id', $schoolId)->update(['is_default' => false]);
            }
            return EveningStudyStatus::create([...$data, 'school_id' => $schoolId]);
        });

        return response()->json($status, 201);
    }

    public function update(Request $request, EveningStudyStatus $eveningStudyStatus)
    {
        $this->authorizeSettings($request);
        abort_unless($eveningStudyStatus->school_id === $this->schoolId($request), 404);
        $data = $this->validated($request, $eveningStudyStatus->school_id, $eveningStudyStatus->id);
        if ($eveningStudyStatus->is_default && !$data['is_default']) {
            return response()->json(['message' => '请直接将其他正常状态设为默认，系统会自动替换当前默认状态'], 422);
        }

        DB::transaction(function () use ($data, $eveningStudyStatus) {
            if ($data['is_default']) {
                EveningStudyStatus::where('school_id', $eveningStudyStatus->school_id)
                    ->whereKeyNot($eveningStudyStatus->id)
                    ->update(['is_default' => false]);
            }
            $eveningStudyStatus->update($data);
        });

        return response()->json($eveningStudyStatus->fresh());
    }

    public function destroy(Request $request, EveningStudyStatus $eveningStudyStatus)
    {
        $this->authorizeSettings($request);
        abort_unless($eveningStudyStatus->school_id === $this->schoolId($request), 404);

        if ($eveningStudyStatus->is_default) {
            return response()->json(['message' => '默认状态不能删除，请先指定其他默认状态'], 422);
        }
        if ((int) SystemSetting::get('boarding_suspension_status_id', 0) === $eveningStudyStatus->id) {
            return response()->json(['message' => '该状态用于暂停住宿，请先指定其他暂停住宿状态'], 422);
        }
        if (AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('requested_evening_status_id', $eveningStudyStatus->id)
            ->where('approval_status', 'pending')
            ->exists()) {
            return response()->json(['message' => '该状态仍有待审批申请，处理完成后才能删除'], 422);
        }
        if (AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('evening_study_status_id', $eveningStudyStatus->id)
            ->whereHas('eveningStudySession', fn ($query) => $query->where('status', '!=', 'completed'))
            ->exists()) {
            return response()->json(['message' => '该状态正在进行中的夜自习点名中使用，完成点名后才能删除'], 422);
        }

        $eveningStudyStatus->delete();
        return response()->json(['message' => '状态已删除']);
    }

    public function setSuspensionStatus(Request $request)
    {
        $this->authorizeSettings($request);
        $data = $request->validate([
            'status_id' => [
                'required',
                Rule::exists('evening_study_statuses', 'id')->where('school_id', $this->schoolId($request)),
            ],
        ]);

        SystemSetting::set('boarding_suspension_status_id', (string) $data['status_id'], '暂停住宿许可对应的夜自习状态');
        return response()->json(['message' => '暂停住宿状态已保存']);
    }

    private function validated(Request $request, int $schoolId, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('evening_study_statuses')->where('school_id', $schoolId)->ignore($ignoreId)],
            'color' => 'required|string|max:20',
            'base_status' => 'required|in:present,absent,excused',
            'is_default' => 'required|boolean',
            'student_requestable' => 'required|boolean',
            'is_active' => 'required|boolean',
            'sort_order' => 'required|integer|min:0|max:999',
        ]);

        if ($data['is_default'] && (!$data['is_active'] || $data['base_status'] !== 'present')) {
            abort(422, '默认状态必须启用并归类为正常');
        }

        return [...$data, 'leave_type_id' => null];
    }

    private function authorizeSettings(Request $request): void
    {
        abort_unless($request->user()?->role === 'system_admin', 403, '仅系统管理员可配置夜自习状态');
    }

    private function schoolId(Request $request): int
    {
        $user = $request->user();
        return (int) ($user->student?->school_id
            ?? $user->teacherClasses()->value('school_id')
            ?? $user->dutyDepartments()->value('school_id')
            ?? $user->managedDepartments()->value('school_id')
            ?? School::query()->value('id'));
    }
}
