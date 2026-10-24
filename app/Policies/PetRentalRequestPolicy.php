<?php

namespace App\Policies;

use App\Models\PetRentalRequest;
use App\Models\User;

class PetRentalRequestPolicy
{
    private function eligible(User $user): bool
    {
        return (bool) $user->is_active
            && $user->email_verified_at !== null;
    }

    public function update(
        User $user,
        PetRentalRequest $petRentalRequest,
    ): bool {
        return $this->eligible($user)
            && $petRentalRequest->rental !== null
            && (int) $petRentalRequest->rental->user_id
                === (int) $user->id;
    }

    public function cancel(
        User $user,
        PetRentalRequest $petRentalRequest,
    ): bool {
        return $this->eligible($user)
            && (int) $petRentalRequest->requester_id
                === (int) $user->id;
    }

    public function delete(
        User $user,
        PetRentalRequest $petRentalRequest,
    ): bool {
        return $this->update($user, $petRentalRequest);
    }
}