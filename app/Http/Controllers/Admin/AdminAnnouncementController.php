<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAnnouncement;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminAnnouncementController extends Controller
{
    private function admin(Request $request): User
    {
        $user = $request->user();

        abort_unless($user, 401);

        abort_unless(
            $user->isAdmin()
                && $user->is_active
                && $user->email_verified_at !== null,
            403,
            'An active admin account with a verified email is required.',
        );

        return $user;
    }

    public function index(Request $request): JsonResponse
    {
        $this->admin($request);

        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json(
            AdminAnnouncement::query()
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function store(
        Request $request,
        NotificationService $notifications,
    ): JsonResponse {
        $admin = $this->admin($request);

        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:3000'],
            'audience' => ['required', Rule::in(['all', 'selected'])],
            'user_ids' => [
                Rule::requiredIf($request->input('audience') === 'selected'),
                Rule::prohibitedIf($request->input('audience') === 'all'),
                'array',
                'min:1',
                'max:100',
            ],
            'user_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'admin_id' => ['prohibited'],
            'recipient_count' => ['prohibited'],
        ]);

        $title = trim($data['title']);
        $body = trim($data['body']);

        if ($title === '' || $body === '') {
            throw ValidationException::withMessages([
                'title' => ['A non-empty title and message are required.'],
            ]);
        }

        $ids = $data['audience'] === 'selected'
            ? array_map('intval', $data['user_ids'])
            : [];

        sort($ids);

        [$announcement, $created] = DB::transaction(function () use (
            $admin,
            $data,
            $title,
            $body,
            $ids,
            $notifications,
        ) {
            // Serialize this admin's publication retries.
            User::query()->lockForUpdate()->findOrFail($admin->id);

            $existing = AdminAnnouncement::query()
                ->where('request_id', $data['request_id'])
                ->first();

            if ($existing) {
                abort_unless(
                    (int) $existing->admin_id === (int) $admin->id,
                    409,
                    'This request ID has already been used.',
                );

                if (
                    $existing->title !== $title
                    || $existing->body !== $body
                    || $existing->audience !== $data['audience']
                    || ($existing->user_ids ?? []) !== $ids
                ) {
                    throw ValidationException::withMessages([
                        'request_id' => [
                            'Use a new request ID for a different announcement.',
                        ],
                    ]);
                }

                return [$existing, false];
            }

            $recipients = User::query()
                ->where('is_active', true)
                ->whereNotNull('email_verified_at');

            if ($data['audience'] === 'selected') {
                $recipients->whereIn('id', $ids);

                if ((clone $recipients)->count() !== count($ids)) {
                    throw ValidationException::withMessages([
                        'user_ids' => [
                            'All selected users must exist, be active and have verified emails.',
                        ],
                    ]);
                }
            }

            $announcement = AdminAnnouncement::create([
                'request_id' => $data['request_id'],
                'admin_id' => $admin->id,
                'title' => $title,
                'body' => $body,
                'audience' => $data['audience'],
                'user_ids' => $ids ?: null,
                'recipient_count' => 0,
            ]);

            $count = 0;

            $recipients->chunkById(200, function ($users) use (
                $notifications,
                $announcement,
                $admin,
                $title,
                $body,
                &$count,
            ) {
                foreach ($users as $user) {
                    $notifications->send(
                        user: $user,
                        type: Notification::TYPE_ADMIN_ANNOUNCEMENT,
                        title: $title,
                        body: $body,
                        data: [
                            'announcement_id' => $announcement->id,
                            'admin_id' => $admin->id,
                        ],
                    );

                    $count++;
                }
            });

            $announcement->update(['recipient_count' => $count]);

            return [$announcement, true];
        });

        return response()->json([
            'message' => $created
                ? 'Announcement sent.'
                : 'Announcement was already sent.',
            'data' => $announcement,
        ], $created ? 201 : 200);
    }
}