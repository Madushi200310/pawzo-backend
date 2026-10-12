<?php

namespace App\Services\Rentals;

use App\Models\PetRental;
use App\Models\PetRentalRequest;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PetRentalRequestService
{
    public function __construct(protected NotificationService $notifications)
    {
    }

    public function create(User $user, PetRental $petRental, array $data): PetRentalRequest
    {
        if ((int) $petRental->user_id === (int) $user->id) {
            throw ValidationException::withMessages([
                'pet_rental_id' => ['You cannot request your own pet rental listing.'],
            ]);
        }

        if ($petRental->status !== 'active') {
            throw ValidationException::withMessages([
                'pet_rental_id' => ['This pet rental listing is not accepting requests.'],
            ]);
        }

        $startDate = Carbon::parse($data['start_date'])->toDateString();
        $endDate = Carbon::parse($data['end_date'])->toDateString();
        $availableFrom = Carbon::parse($petRental->available_from)->toDateString();
        $availableTo = Carbon::parse($petRental->available_to)->toDateString();

        if ($startDate < $availableFrom || $endDate > $availableTo) {
            throw ValidationException::withMessages([
                'start_date' => ['Requested dates must fall within the rental availability window.'],
            ]);
        }

        if ($startDate > $endDate) {
            throw ValidationException::withMessages([
                'end_date' => ['The end date must be on or after the start date.'],
            ]);
        }

        $overlap = PetRentalRequest::query()
            ->where('pet_rental_id', $petRental->id)
            ->where('status', 'accepted')
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($nested) use ($startDate, $endDate) {
                        $nested->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            })
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' => ['These dates overlap with an already-accepted request.'],
            ]);
        }

        $request = DB::transaction(function () use ($user, $petRental, $data, $startDate, $endDate) {
            return PetRentalRequest::create([
                'pet_rental_id' => $petRental->id,
                'requester_id' => $user->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'message' => $data['message'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'status' => 'pending',
            ]);
        });

        $this->notifications->rentalRequest(
            owner: $petRental->user,
            rentalId: $petRental->id,
            petName: $petRental->pet_name,
            requesterId: $user->id,
            requesterName: $user->name,
            startDate: $startDate,
            endDate: $endDate,
        );

        return $request->load(['rental', 'requester']);
    }

    public function respond(PetRentalRequest $petRentalRequest, string $status, ?string $message = null): PetRentalRequest
    {
        return DB::transaction(function () use ($petRentalRequest, $status, $message) {
            $petRentalRequest->status = $status;
            $petRentalRequest->message = $message ?? $petRentalRequest->message;
            $petRentalRequest->owner_response = $message ?? $petRentalRequest->owner_response;
            $petRentalRequest->responded_at = now();
            $petRentalRequest->save();

            if ($status === 'accepted') {
                PetRentalRequest::query()
                    ->where('pet_rental_id', $petRentalRequest->pet_rental_id)
                    ->where('id', '!=', $petRentalRequest->id)
                    ->whereIn('status', ['pending'])
                    ->update(['status' => 'rejected']);
            }

            return $petRentalRequest->fresh()->load(['rental', 'requester']);
        });
    }
}
