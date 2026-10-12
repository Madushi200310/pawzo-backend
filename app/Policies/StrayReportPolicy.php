<?php

namespace App\Policies;

use App\Models\StrayReport;
use App\Models\User;

// Laravel discovers this policy by the model/policy naming convention.
class StrayReportPolicy
{
    public function update(User $user, StrayReport $report): bool
    {
        return (int) $report->user_id === (int) $user->id;
    }

    public function delete(User $user, StrayReport $report): bool
    {
        return $this->update($user, $report);
    }
}