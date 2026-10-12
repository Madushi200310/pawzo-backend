<?php

namespace App\Policies;

use App\Models\PetService;
use App\Models\User;

class PetServicePolicy
{
    public function update(User $user, PetService $service): bool
    {
        return (int) $service->user_id === (int) $user->id;
    }

    public function delete(User $user, PetService $service): bool
    {
        return $this->update($user, $service);
    }
}