<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplaintController extends Controller
{
    private function verifiedUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user, 401);

        abort_unless(
            $user->is_active && $user->email_verified_at !== null,
            403,
            'An active account with a verified email is required.',
        );

        return $user;
    }

    private function admin(Request $request): User
    {
        $user = $this->verifiedUser($request);

        abort_unless($user->isAdmin(), 403, 'Admin access required.');

        return $user;
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'target_type' => [
                'required',
                Rule::in(array_keys(Complaint::TARGETS)),
            ],
            'target_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(Complaint::REASONS)],
            'description' => ['required', 'string', 'max:3000'],
            'reporter_id' => ['prohibited'],
            'status' => ['prohibited'],
            'admin_notes' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
        ]);

        $description = trim($data['description']);

        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => ['Please describe the problem.'],
            ]);
        }

        $complaint = DB::transaction(function () use (
            $user,
            $data,
            $description,
        ) {
            // Serialize duplicate checks for this reporter.
            User::query()->lockForUpdate()->findOrFail($user->id);

            $model = Complaint::TARGETS[$data['target_type']];

            if (! $model::query()->whereKey($data['target_id'])->exists()) {
                throw ValidationException::withMessages([
                    'target_id' => ['The selected target is unavailable.'],
                ]);
            }

            $duplicate = Complaint::query()
                ->where('reporter_id', $user->id)
                ->where('target_type', $data['target_type'])
                ->where('target_id', $data['target_id'])
                ->whereIn('status', ['pending', 'investigating'])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'target_id' => [
                        'You already have an open complaint for this target.',
                    ],
                ]);
            }

            return Complaint::create([
                'reporter_id' => $user->id,
                'target_type' => $data['target_type'],
                'target_id' => $data['target_id'],
                'reason' => $data['reason'],
                'description' => $description,
            ]);
        });

        return response()->json([
            'message' => 'Complaint submitted for admin review.',
            'data' => $complaint->fresh()->only([
                'id',
                'target_type',
                'target_id',
                'reason',
                'description',
                'status',
                'created_at',
            ]),
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json(
            Complaint::query()
                ->select([
                    'id',
                    'reporter_id',
                    'target_type',
                    'target_id',
                    'reason',
                    'description',
                    'status',
                    'closed_at',
                    'created_at',
                    'updated_at',
                ])
                ->where('reporter_id', $user->id)
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->admin($request);

        $data = $request->validate([
            'status' => [
                'sometimes',
                'required',
                Rule::in(Complaint::STATUSES),
            ],
            'target_type' => [
                'sometimes',
                'required',
                Rule::in(array_keys(Complaint::TARGETS)),
            ],
            'reason' => [
                'sometimes',
                'required',
                Rule::in(Complaint::REASONS),
            ],
            'reporter_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
            ],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Complaint::query()->with('reporter:id,name');

        foreach (
            ['status', 'target_type', 'reason', 'reporter_id']
            as $field
        ) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function adminShow(
        Request $request,
        Complaint $complaint,
    ): JsonResponse {
        $this->admin($request);

        return response()->json([
            'data' => $complaint->load([
                'reporter:id,name,email',
                'reviewer:id,name',
            ]),
        ]);
    }

    public function respond(
        Request $request,
        Complaint $complaint,
    ): JsonResponse {
        $admin = $this->admin($request);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(['investigating', 'resolved', 'dismissed']),
            ],
            'admin_notes' => ['required', 'string', 'max:3000'],
        ]);

        $notes = trim($data['admin_notes']);

        if ($notes === '') {
            throw ValidationException::withMessages([
                'admin_notes' => ['Please record the review outcome.'],
            ]);
        }

        $updated = DB::transaction(function () use (
            $complaint,
            $admin,
            $data,
            $notes,
        ) {
            $locked = Complaint::query()
                ->lockForUpdate()
                ->findOrFail($complaint->id);

            if (in_array($locked->status, ['resolved', 'dismissed'], true)) {
                if (
                    $locked->status === $data['status']
                    && $locked->admin_notes === $notes
                ) {
                    return $locked;
                }

                throw ValidationException::withMessages([
                    'status' => ['This complaint is already closed.'],
                ]);
            }

            $locked->forceFill([
                'status' => $data['status'],
                'admin_notes' => $notes,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'closed_at' => in_array(
                    $data['status'],
                    ['resolved', 'dismissed'],
                    true,
                ) ? now() : null,
            ])->save();

            return $locked;
        });

        return response()->json([
            'message' => 'Complaint updated.',
            'data' => $updated,
        ]);
    }
}