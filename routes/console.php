<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Exits non-zero while any payment webhook failure is unresolved. Scheduler
 * failures and error-level logs are routed to the operations alert channel,
 * so an unresolved failure pages the on-call team within five minutes.
 */
Schedule::command('webhooks:check-failures')
    ->everyFiveMinutes()
    ->withoutOverlapping();
