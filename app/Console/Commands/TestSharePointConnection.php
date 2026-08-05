<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ExcelFileProvider;
use App\Exceptions\SharePointException;
use App\Services\MicrosoftGraphClient;
use App\ValueObjects\ConnectionStatus;
use Illuminate\Console\Command;

/**
 * Diagnostic command for setting up SharePoint: authenticates, resolves
 * the configured site and document library, and lists what it finds -
 * without syncing anything into MySQL. Meant to be run once after
 * filling in .env, to confirm the configuration is correct before
 * relying on the scheduled sharepoint:sync-excel command.
 */
final class TestSharePointConnection extends Command
{
    protected $signature = 'sharepoint:test';

    protected $description = 'Test the SharePoint/Microsoft Graph connection without syncing anything';

    public function __construct(
        private readonly ExcelFileProvider $excelFiles,
        private readonly MicrosoftGraphClient $graphClient,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $status = $this->excelFiles->healthCheck();

        if ($status === ConnectionStatus::NotConfigured) {
            $this->info('SharePoint is not configured.');

            return self::SUCCESS;
        }

        if ($status === ConnectionStatus::Disconnected) {
            $this->error(
                'Could not connect to SharePoint. Check SHAREPOINT_SITE_URL, '.
                'SHAREPOINT_TENANT_ID, SHAREPOINT_CLIENT_ID, and SHAREPOINT_CLIENT_SECRET in .env.'
            );

            return self::FAILURE;
        }

        $this->info('Authenticated successfully.');

        try {
            $this->info('Resolved site ID: '.$this->graphClient->resolveSiteId());

            $this->info('Available document libraries:');
            foreach ($this->graphClient->listDocumentLibraries() as $library) {
                $this->line("  - {$library['name']}");
            }

            $excelFolder = (string) config('sharepoint.excel_folder');
            $this->info("Excel files in \"{$excelFolder}\":");
            foreach ($this->excelFiles->listExcelFiles() as $file) {
                $this->line("  - {$file->name}");
            }
        } catch (SharePointException $e) {
            $this->error("SharePoint check failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
