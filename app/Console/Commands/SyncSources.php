<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SyncSourcesAction;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Console entry point for syncing every configured Source's Excel file
 * into data_records. Thin by design - all parsing/upsert logic lives in
 * SyncSourcesAction.
 *
 * Scheduled to run every 10 minutes in routes/console.php. One source's
 * failure (missing file, bad download, unparseable content) is logged
 * and skipped - it never stops the rest of the sources from syncing, and
 * never fails the whole scheduled run.
 */
final class SyncSources extends Command
{
    protected $signature = 'sources:sync';

    protected $description = 'Sync every configured source\'s Excel file into data_records';

    public function __construct(
        private readonly SyncSourcesAction $action,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sources = Source::all();

        if ($sources->isEmpty()) {
            $this->info('No sources configured.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$sources->count()} source(s)...");

        $failed = 0;

        foreach ($sources as $source) {
            try {
                $count = $this->action->sync($source, function (int $rowsSoFar) use ($source): void {
                    $this->line("  [{$source->department->slug}] {$source->name}: {$rowsSoFar} row(s) so far...");
                });
                $this->info("  [{$source->department->slug}] {$source->name}: {$count} record(s) synced.");
            } catch (Throwable $e) {
                $failed++;

                Log::channel((string) config('chatbot.log_channel'))->error('Source sync failed', [
                    'source_id' => $source->id,
                    'department' => $source->department->slug,
                    'name' => $source->name,
                    'error' => $e->getMessage(),
                ]);

                $this->error("  [{$source->department->slug}] {$source->name}: FAILED - {$e->getMessage()}");
            }
        }

        $this->info("Done. {$failed} failure(s).");

        return self::SUCCESS;
    }
}
