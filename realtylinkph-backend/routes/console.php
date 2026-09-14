<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the homepage "Featured" ranking fresh (requires the scheduler/cron to run).
Schedule::command('properties:score-featured')->hourly();

// Rejected applicants' IDs and selfies are deleted once their re-apply
// cooldown passes. Hourly so a file never outlives the cooldown by much.
Schedule::command('agents:purge-rejected-documents')->hourly();
