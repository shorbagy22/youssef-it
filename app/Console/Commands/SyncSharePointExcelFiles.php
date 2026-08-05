<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SharePoint\SyncSharePointExcelFilesAction;
use App\Exceptions\SharePointException;
use Illuminate\Console\Command;

/**
 * Console entry point for syncing SharePoint Excel files into MySQL.
 * Thin by design - all logic lives in SyncSharePointExcelFilesAction.
 *
 * Scheduled to run daily in routes/console.php, matching how often the
 * source Excel files themselves change.
 */
final class SyncSharePointExcelFiles extends Command
{
    protected $signature = 'sharepoint:sync-excel';

    protected $description = 'Sync Excel files from the configured SharePoint drive into MySQL';

    public function __construct(
        private readonly SyncSharePointExcelFilesAction $action,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->action->handle();
        } catch (SharePointException $e) {
            $this->error("Could not list SharePoint files: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($result->notConfigured) {
            $this->info('SharePoint is not configured.');

            return self::SUCCESS;
        }

        $this->info(
            "Checked {$result->checked} file(s): {$result->synced} synced, ".
            "{$result->skipped} unchanged, {$result->failed} failed."
        );

        foreach ($result->errors as $error) {
            $this->error($error);
        }

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
