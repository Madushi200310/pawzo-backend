<?php

namespace App\Console\Commands;

use App\Services\MedicationNotificationService;
use Illuminate\Console\Command;

class SendMedicationNotifications extends Command
{
    protected $signature = 'medications:notify';

    protected $description = 'Send due recurring medication notifications';

    public function handle(MedicationNotificationService $service): int
    {
        $sent = $service->sendDue();

        $this->info("Sent {$sent} medication notification(s).");

        return self::SUCCESS;
    }
}