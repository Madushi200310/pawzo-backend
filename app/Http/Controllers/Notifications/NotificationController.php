<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ListNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function index(
        ListNotificationsRequest $request
    ): AnonymousResourceCollection {
        $query = Notification::query()
            ->where('user_id', $request->user()->id);

        $data = $request->validated();

        if (isset($data['status'])) {
            if ($data['status'] === 'unread') {
                $query->unread();
            } elseif ($data['status'] === 'read') {
                $query->read();
            }
        }

        if (isset($data['type'])) {
            $query->where('type', $data['type']);
        }

        return NotificationResource::collection(
            $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markAsRead(
        Notification $notification,
        NotificationService $service
    ): NotificationResource {
        Gate::authorize('update', $notification);

        // Re-fetch after service update so the resource sees the new read_at.
        $updated = $service->markAsRead($notification);

        return (new NotificationResource($updated))
            ->additional(['message' => 'Notification marked as read.']);
    }

    public function markAllAsRead(
        Request $request,
        NotificationService $service
    ): JsonResponse {
        $count = $service->markAllAsRead($request->user());

        return response()->json([
            'message' => "Marked {$count} notification(s) as read.",
            'count' => $count,
        ]);
    }

    public function destroy(Notification $notification): JsonResponse
    {
        Gate::authorize('delete', $notification);
        $notification->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}