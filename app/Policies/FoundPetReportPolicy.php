<?php

namespace App\Policies;

use App\Models\FoundPetReport;
use App\Models\User;

// Laravel discovers this policy by the model/policy naming convention.
class FoundPetReportPolicy
{
    public function update(User $user, FoundPetReport $report): bool
    {
        return (int) $report->user_id === (int) $user->id;
    }

    public function delete(User $user, FoundPetReport $report): bool
    {
        return $this->update($user, $report);
    }
}