<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RollCallRecord extends Model
{
    protected $fillable = [
        'roll_call_id',
        'student_id',
        'status',
        'leave_type_id',
        'leave_detail',
        'leave_status',
        'marked_at',
        'marked_by',
    ];

    protected $casts = [
        'marked_at' => 'datetime',
    ];

    /**
     * 序列化日期时使用东八区时区
     * 避免前端解析 UTC 时间后再次转换导致时间错误
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
    }

    public function rollCall()
    {
        return $this->belongsTo(RollCall::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function marker()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function isPresent(): bool
    {
        return $this->status === 'present';
    }

    public function isAbsent(): bool
    {
        return $this->status === 'absent';
    }

    public function isOnLeave(): bool
    {
        return $this->status === 'on_leave';
    }
}
