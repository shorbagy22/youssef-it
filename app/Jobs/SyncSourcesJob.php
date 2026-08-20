<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs sources:sync on the queue instead of the request that triggers it -
 * see Admin\SourceController::sync(), which used to call Artisan::call()
 * directly and block the admin's browser for the full sync (url-type
 * sources download over HTTP and every source gets parsed as Excel).
 */
final class SyncSourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Artisan::call('sources:sync');
    }
}
