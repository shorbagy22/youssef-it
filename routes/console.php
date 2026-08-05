<?php

declare(strict_types=1);

use App\Console\Commands\SyncSharePointExcelFiles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cadence is configurable via SHAREPOINT_SYNC_SCHEDULE (config/sharepoint.php)
// rather than hardcoded here, so it can change without a code change.
Schedule::command(SyncSharePointExcelFiles::class)->cron((string) config('sharepoint.sync_schedule'));
