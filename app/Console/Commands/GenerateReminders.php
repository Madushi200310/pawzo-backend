<?php

namespace App\Console\Commands;

use App\Services\ReminderGeneratorService;
use Illuminate\Console\Command;

class GenerateReminders extends Command
{
    protected $signature = 'reminders:generate';

    protected $description = 'Auto-generate vaccination & medication reminders for all pets';

    public function handle(ReminderGeneratorService $service): int
    {
        $this->info('Scanning pets...');

        $result = $service->generateForAllPets();

        $this->info("Done. Created {$result['created']} new reminder(s).");

        return self::SUCCESS;
    }
}