<?php

namespace App\Policies;

use App\Models\LostPetReport;
use App\Models\User;

// Laravel discovers this policy by the model/policy naming convention.
class LostPetReportPolicy
{
    public function update(User $user, LostPetReport $report): bool
    {
        return (int) $report->user_id === (int) $user->id;
    }

    public function delete(User $user, LostPetReport $report): bool
    {
        return $this->update($user, $report);
    }
}