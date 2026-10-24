<?php

namespace App\Http\Controllers\Services;

use App\Http\Controllers\Controller;
use App\Models\PetService;
use App\Models\ServiceBooking;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceBookingController extends Controller
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

    private function ensureBookable(PetService $service): void
    {
        $business = $service->business;
        $owner = $service->user;

        if (
            $service->status !== 'approved'
            || ! $business
            || $business->status !== 'approved'
            || (int) $business->user_id !== (int) $service->user_id
            || ! $owner
            || ! $owner->is_active
            || $owner->email_verified_at === null
        ) {
            throw ValidationException::withMessages([
                'pet_service_id' => [
                    'This service is not currently accepting bookings.',
                ],
            ]);
        }
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $bookings = ServiceBooking::query()
            ->with('service:id,name,user_id,business_id')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15))
            ->withQueryString();

        return response()->json($bookings);
    }

    public function incoming(Request $request): JsonResponse
    {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $bookings = ServiceBooking::query()
            ->with([
                'service:id,name,user_id,business_id',
                'user:id,name',
            ])
            ->whereHas('service', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15))
            ->withQueryString();

        return response()->json($bookings);
    }

    public function store(
        Request $request,
        PetService $petService,
    ): JsonResponse {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'scheduled_at' => [
                'required',
                'string',
                'date',
                'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/i',
                'after:now',
            ],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'user_id' => ['prohibited'],
            'pet_service_id' => ['prohibited'],
            'reference' => ['prohibited'],
            'status' => ['prohibited'],
        ]);

        $booking = DB::transaction(function () use (
            $user,
            $petService,
            $data,
        ) {
            $service = PetService::query()
                ->lockForUpdate()
                ->findOrFail($petService->id);

            $this->ensureBookable($service);

            if ((int) $service->user_id === (int) $user->id) {
                throw ValidationException::withMessages([
                    'pet_service_id' => [
                        'You cannot book your own service.',
                    ],
                ]);
            }

            $scheduledAt = Carbon::parse($data['scheduled_at'])->utc();

            $duplicate = ServiceBooking::query()
                ->where('pet_service_id', $service->id)
                ->where('user_id', $user->id)
                ->where('scheduled_at', $scheduledAt)
                ->whereIn('status', ['pending', 'accepted'])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'scheduled_at' => [
                        'You already have an active booking request for this time.',
                    ],
                ]);
            }

            return ServiceBooking::create([
                'reference' => (string) Str::uuid(),
                'pet_service_id' => $service->id,
                'user_id' => $user->id,
                'scheduled_at' => $scheduledAt,
                'message' => $data['message'] ?? null,
                'status' => 'pending',
            ]);
        });

        return response()->json([
            'message' => 'Service booking submitted.',
            'data' => $booking,
        ], 201);
    }

    public function respond(
        Request $request,
        ServiceBooking $serviceBooking,
    ): JsonResponse {
        $user = $this->verifiedUser($request);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'accepted',
                    'rejected',
                    'completed',
                    'cancelled',
                ]),
            ],
            'provider_response' => [
                Rule::prohibitedIf(
                    $request->input('status') === 'cancelled'
                ),
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
            'user_id' => ['prohibited'],
            'pet_service_id' => ['prohibited'],
            'reference' => ['prohibited'],
            'scheduled_at' => ['prohibited'],
            'completed_at' => ['prohibited'],
        ]);

        $updated = DB::transaction(function () use (
            $serviceBooking,
            $user,
            $data,
        ) {
            $booking = ServiceBooking::query()
                ->lockForUpdate()
                ->findOrFail($serviceBooking->id);

            $service = PetService::query()
                ->lockForUpdate()
                ->findOrFail($booking->pet_service_id);

            $target = $data['status'];

            if ($target === 'cancelled') {
                abort_unless(
                    (int) $booking->user_id === (int) $user->id,
                    403,
                    'Only the customer can cancel this booking.',
                );
            } else {
                abort_unless(
                    (int) $service->user_id === (int) $user->id,
                    403,
                    'Only the service owner can respond to this booking.',
                );
            }

            if ($booking->status === $target) {
                return $booking;
            }

            $allowed = match ($booking->status) {
                'pending' => ['accepted', 'rejected', 'cancelled'],
                'accepted' => ['completed', 'cancelled'],
                default => [],
            };

            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => [
                        'This booking cannot move to the requested status.',
                    ],
                ]);
            }

            if ($target === 'accepted') {
                $this->ensureBookable($service);

                $customer = $booking->user;

                if (
                    ! $customer
                    || ! $customer->is_active
                    || $customer->email_verified_at === null
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'The customer account is not eligible.',
                        ],
                    ]);
                }
            }

            if (
                $target === 'completed'
                && $booking->scheduled_at->isFuture()
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'A booking cannot be completed before its scheduled time.',
                    ],
                ]);
            }

            $booking->status = $target;

            if ($target === 'cancelled') {
                $booking->cancelled_at = now();
            } else {
                if (array_key_exists('provider_response', $data)) {
                    $booking->provider_response = $data['provider_response'];
                }

                $booking->responded_at = now();

                if ($target === 'completed') {
                    $booking->completed_at = now();
                }
            }

            $booking->save();

            return $booking;
        });

        return response()->json([
            'message' => 'Service booking updated.',
            'data' => $updated,
        ]);
    }
}