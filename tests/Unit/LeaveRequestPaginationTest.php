<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\LeaveRequestController;
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
}
