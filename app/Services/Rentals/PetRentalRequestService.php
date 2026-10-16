<?php

namespace App\Services\Rentals;

use App\Models\Notification;
use App\Models\PetRental;
use App\Models\PetRentalRequest;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PetRentalRequestService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    public function create(
        User $user,
        PetRental $petRental,
        array $data,
    ): PetRentalRequest {
        return DB::transaction(function () use ($user, $petRental, $data) {
            $rental = PetRental::query()
                ->lockForUpdate()
                ->findOrFail($petRental->id);

            if ((int) $rental->user_id === (int) $user->id) {
                throw ValidationException::withMessages([
                    'pet_rental_id' => [
                        'You cannot request your own pet rental listing.',
                    ],
                ]);
            }

            $startDate = Carbon::parse($data['start_date'])->toDateString();
            $endDate = Carbon::parse($data['end_date'])->toDateString();

            $this->validateAvailability($rental, $startDate, $endDate);
            $this->ensureNoAcceptedOverlap($rental, $startDate, $endDate);

            $created = PetRentalRequest::create([
                'pet_rental_id' => $rental->id,
                'requester_id' => $user->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'message' => $data['message'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'status' => 'pending',
            ]);

            $this->notifications->rentalRequest(
                owner: $rental->user()->firstOrFail(),
                rentalId: $rental->id,
                petName: $rental->pet_name,
                requesterId: $user->id,
                requesterName: $user->name,
                startDate: $startDate,
                endDate: $endDate,
            );

            return $created->load(['rental', 'requester']);
        });
    }

    public function respond(
        PetRentalRequest $petRentalRequest,
        string $status,
        ?string $message = null,
        ?User $actor = null,
    ): PetRentalRequest {
        $actor ??= auth()->user();

        abort_unless($actor instanceof User, 403);

        if (! in_array($status, ['accepted', 'rejected', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid rental request status.'],
            ]);
        }

        return DB::transaction(function () use (
            $petRentalRequest,
            $status,
            $message,
            $actor,
        ) {
            $rental = PetRental::query()
                ->lockForUpdate()
                ->findOrFail($petRentalRequest->pet_rental_id);

            $locked = PetRentalRequest::query()
                ->lockForUpdate()
                ->findOrFail($petRentalRequest->id);

            $locked->setRelation('rental', $rental);

            Gate::forUser($actor)->authorize(
                $status === 'cancelled' ? 'cancel' : 'update',
                $locked
            );

            if ($locked->status === $status) {
                return $locked->load(['rental', 'requester']);
            }

            $allowed = match ($status) {
                'accepted' => $locked->status === 'pending',
                'rejected', 'cancelled' => in_array(
                    $locked->status,
                    ['pending', 'accepted'],
                    true
                ),
            };

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'status' => [
                        'This request cannot move to the requested status.',
                    ],
                ]);
            }

            if ($status === 'accepted') {
                $startDate = $locked->start_date->toDateString();
                $endDate = $locked->end_date->toDateString();

                $this->validateAvailability($rental, $startDate, $endDate);

                $this->ensureNoAcceptedOverlap(
                    $rental,
                    $startDate,
                    $endDate,
                    (int) $locked->id,
                );
            }

            $locked->status = $status;
            $locked->responded_at = now();

            if ($status !== 'cancelled' && $message !== null) {
                $locked->owner_response = $message;
            }

            $locked->save();

            $this->notifyStatus($locked, $status, $message);

            if ($status === 'accepted') {
                $overlapping = $this->overlappingRequests(
                    $rental,
                    $locked->start_date->toDateString(),
                    $locked->end_date->toDateString(),
                )
                    ->where('id', '!=', $locked->id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->get();

                foreach ($overlapping as $other) {
                    $reason = 'The requested dates are no longer available.';

                    $other->status = 'rejected';
                    $other->owner_response = $reason;
                    $other->responded_at = now();
                    $other->save();

                    $other->setRelation('rental', $rental);

                    $this->notifyStatus($other, 'rejected', $reason);
                }
            }

            return $locked->fresh()->load(['rental', 'requester']);
        });
    }

    private function validateAvailability(
        PetRental $rental,
        string $startDate,
        string $endDate,
    ): void {
        if ($rental->status !== 'active') {
            throw ValidationException::withMessages([
                'pet_rental_id' => [
                    'This pet rental listing is not accepting requests.',
                ],
            ]);
        }

        if ($startDate > $endDate) {
            throw ValidationException::withMessages([
                'end_date' => [
                    'The end date must be on or after the start date.',
                ],
            ]);
        }

        $availableFrom = Carbon::parse(
            $rental->available_from
        )->toDateString();

        $availableTo = Carbon::parse(
            $rental->available_to
        )->toDateString();

        if ($startDate < $availableFrom || $endDate > $availableTo) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'Requested dates must fall within the rental availability window.',
                ],
            ]);
        }
    }

    private function overlappingRequests(
        PetRental $rental,
        string $startDate,
        string $endDate,
    ): Builder {
        // Compare calendar dates, including both boundary days.
        return PetRentalRequest::query()
            ->where('pet_rental_id', $rental->id)
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate);
    }

    private function ensureNoAcceptedOverlap(
        PetRental $rental,
        string $startDate,
        string $endDate,
        ?int $ignoreId = null,
    ): void {
        $query = $this->overlappingRequests(
            $rental,
            $startDate,
            $endDate,
        )->where('status', 'accepted');

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'These dates overlap with an already-accepted request.',
                ],
            ]);
        }
    }

    private function notifyStatus(
        PetRentalRequest $request,
        string $status,
        ?string $message,
    ): void {
        $rental = $request->rental;

        $recipient = $status === 'cancelled'
            ? $rental->user()->firstOrFail()
            : $request->requester()->firstOrFail();

        $body = $status === 'cancelled'
            ? "The rental request for {$rental->pet_name} was cancelled by the requester."
            : "Your rental request for {$rental->pet_name} was {$status}.";

        $this->notifications->send(
            user: $recipient,
            type: Notification::TYPE_RENTAL_REQUEST,
            title: match ($status) {
                'accepted' => 'Rental request accepted',
                'rejected' => 'Rental request rejected',
                'cancelled' => 'Rental request cancelled',
            },
            body: $body,
            data: [
                'event' => 'rental_request_'.$status,
                'rental_request_id' => (int) $request->id,
                'rental_id' => (int) $rental->id,
                'requester_id' => (int) $request->requester_id,
                'status' => $status,
                'start_date' => $request->start_date->toDateString(),
                'end_date' => $request->end_date->toDateString(),
                'response_message' => $message,
            ],
            actionUrl: $status === 'cancelled'
                ? "/api/rentals/{$rental->id}/requests"
                : '/api/rental-requests/mine',
        );
    }
}