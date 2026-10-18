<?php

namespace App\Console\Commands;

use App\Services\ReminderNotificationService;
use Illuminate\Console\Command;

class SendReminderNotifications extends Command
{
    protected $signature = 'reminders:notify';

    protected $description = 'Create notifications for due health reminders';

    public function handle(ReminderNotificationService $service): int
    {
        $sent = $service->sendDue();

        $this->info("Created {$sent} reminder notification(s).");

        return self::SUCCESS;
    }
}