<?php

declare(strict_types=1);

use App\Console\Commands\SyncSharePointExcelFiles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The source Excel files in SharePoint are updated daily, so the sync
// runs on the same cadence.
Schedule::command(SyncSharePointExcelFiles::class)->daily();
