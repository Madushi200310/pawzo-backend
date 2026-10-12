<?php

namespace App\Policies;

use App\Models\PetRental;
use App\Models\User;

class PetRentalPolicy
{
    public function update(User $user, PetRental $petRental): bool
    {
        return (int) $petRental->user_id === (int) $user->id;
    }

    public function delete(User $user, PetRental $petRental): bool
    {
        return $this->update($user, $petRental);
    }
}
