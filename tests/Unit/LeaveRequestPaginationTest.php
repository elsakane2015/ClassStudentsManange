<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\LeaveRequestController;
use App\Models\AttendanceRecord;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class LeaveRequestPaginationTest extends TestCase
{
    public function test_paginate_grouped_requests_slices_pages_and_reports_has_more(): void
    {
        $items = collect(range(1, 45))->map(fn ($id) => ['id' => $id]);

        $page1 = LeaveRequestController::paginateGroupedRequests($items, 1, 20);
        $this->assertCount(20, $page1['data']);
        $this->assertSame(1, $page1['data']->first()['id']);
        $this->assertSame(20, $page1['data']->last()['id']);
        $this->assertSame(45, $page1['total']);
        $this->assertSame([
            'current_page' => 1,
            'last_page' => 3,
            'per_page' => 20,
            'total' => 45,
            'has_more' => true,
        ], $page1['meta']);

        $page3 = LeaveRequestController::paginateGroupedRequests($items, 3, 20);
        $this->assertCount(5, $page3['data']);
        $this->assertSame(41, $page3['data']->first()['id']);
        $this->assertSame(45, $page3['data']->last()['id']);
        $this->assertFalse($page3['meta']['has_more']);
    }

    public function test_paginate_grouped_requests_handles_empty_and_out_of_bounds_pages(): void
    {
        $empty = LeaveRequestController::paginateGroupedRequests(new Collection(), 1, 20);
        $this->assertCount(0, $empty['data']);
        $this->assertSame(0, $empty['total']);
        $this->assertSame(1, $empty['meta']['last_page']);
        $this->assertFalse($empty['meta']['has_more']);

        $items = collect([['id' => 1], ['id' => 2]]);
        $beyond = LeaveRequestController::paginateGroupedRequests($items, 5, 20);
        $this->assertCount(0, $beyond['data']);
        $this->assertFalse($beyond['meta']['has_more']);
    }

    public function test_resolve_leave_group_reason_reads_details_text_and_reason_without_duplication(): void
    {
        // 1. 「特殊」假类仅把原因存在 details.text，reason 为空
        $special = new AttendanceRecord();
        $special->setRawAttributes([
            'reason' => null,
            'details' => json_encode(['text' => '参加技能大赛集训']),
        ]);
        $this->assertSame('参加技能大赛集训', LeaveRequestController::resolveLeaveGroupReason([$special]));

        // 2. 同时填写了 details.text 和不同内容的 reason 时合并展示
        $both = new AttendanceRecord();
        $both->setRawAttributes([
            'reason' => '全天在实训室',
            'details' => json_encode(['text' => '技能训练']),
        ]);
        $this->assertSame('技能训练 · 全天在实训室', LeaveRequestController::resolveLeaveGroupReason([$both]));

        // 3. 两者相同时去重不重复
        $same = new AttendanceRecord();
        $same->setRawAttributes([
            'reason' => '技能训练',
            'details' => json_encode(['text' => '技能训练']),
        ]);
        $this->assertSame('技能训练', LeaveRequestController::resolveLeaveGroupReason([$same]));

        // 4. 普通病假仅填 reason
        $sick = new AttendanceRecord();
        $sick->setRawAttributes(['reason' => '感冒发烧需要去医院']);
        $this->assertSame('感冒发烧需要去医院', LeaveRequestController::resolveLeaveGroupReason([$sick]));
    }
}
