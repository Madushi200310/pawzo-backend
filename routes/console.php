<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reminders:generate')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('reminders:notify')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('medications:notify')
    ->everyMinute()
    ->withoutOverlapping();