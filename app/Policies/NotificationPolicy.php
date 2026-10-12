<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

class NotificationPolicy
{
    public function update(User $user, Notification $notification): bool
    {
        return (int) $notification->user_id === (int) $user->id;
    }

    public function delete(User $user, Notification $notification): bool
    {
        return $this->update($user, $notification);
    }
}