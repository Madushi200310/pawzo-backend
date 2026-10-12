<?php

namespace App\Policies;

use App\Models\PetRentalRequest;
use App\Models\User;

class PetRentalRequestPolicy
{
    public function update(User $user, PetRentalRequest $petRentalRequest): bool
    {
        return (int) $petRentalRequest->rental->user_id === (int) $user->id;
    }

    public function delete(User $user, PetRentalRequest $petRentalRequest): bool
    {
        return $this->update($user, $petRentalRequest);
    }
}
