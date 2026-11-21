<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class AppNotificationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $query = $request->user()
            ->appNotifications()
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $paginator = $query->paginate($perPage);
        $unreadCount = $request->user()
            ->appNotifications()
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => $paginator->items(),
            'unread_count' => $unreadCount,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $unreadCount = $request->user()
            ->appNotifications()
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'unread_count' => $unreadCount,
        ]);
    }

    public function markRead(Request $request, AppNotification $appNotification)
    {
        if ($appNotification->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($appNotification->read_at === null) {
            $appNotification->update(['read_at' => now()]);
        }

        return response()->json([
            'message' => 'Marked as read',
            'notification' => $appNotification->fresh(),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $updated = $request->user()
            ->appNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read',
            'updated_count' => $updated,
        ]);
    }

    public function destroy(Request $request, AppNotification $appNotification)
    {
        if ($appNotification->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $appNotification->delete();

        return response()->json(['message' => 'Notification deleted']);
    }
}
