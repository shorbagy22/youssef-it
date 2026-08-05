<?php

declare(strict_types=1);

namespace App\Actions\SharePoint;

use App\Contracts\ExcelFileProvider;
use App\DTOs\SyncResult;
use App\Exceptions\SharePointException;
use App\Repositories\SyncedDocumentRepository;
use Illuminate\Support\Facades\Storage;

/**
 * Syncs every Excel file in the configured SharePoint drive into MySQL:
 * lists what's there, skips anything unchanged since the last sync, and
 * downloads + stores anything new or modified.
 *
 * Business logic only - no HTTP, no console I/O. Depends on the
 * ExcelFileProvider contract rather than SharePointExcelService directly,
 * so the document source could be swapped (or mocked in tests) without
 * touching this class. The entry point that calls this is the
 * sharepoint:sync-excel console command.
 */
final class SyncSharePointExcelFilesAction
{
    private const string STORAGE_DISK = 'local';

    private const string STORAGE_DIRECTORY = 'sharepoint-excel';

    public function __construct(
        private readonly ExcelFileProvider $excelFiles,
        private readonly SyncedDocumentRepository $documents,
    ) {}

    public function handle(): SyncResult
    {
        $checked = 0;
        $synced = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->excelFiles->listExcelFiles() as $remoteFile) {
            $checked++;

            $existing = $this->documents->findBySharePointId($remoteFile->id);

            // Unchanged since the last successful sync - nothing to do.
            if ($existing?->modified_at?->equalTo($remoteFile->modifiedAt) === true) {
                $skipped++;

                continue;
            }

            try {
                $content = $this->excelFiles->downloadFile($remoteFile->id);
                $localPath = self::STORAGE_DIRECTORY."/{$remoteFile->id}.xlsx";

                Storage::disk(self::STORAGE_DISK)->put($localPath, $content);

                $this->documents->markSynced(
                    sharePointId: $remoteFile->id,
                    fileName: $remoteFile->name,
                    modifiedAt: $remoteFile->modifiedAt,
                    checksum: hash('sha256', $content),
                    localPath: $localPath,
                    size: $remoteFile->size,
                );

                $synced++;
            } catch (SharePointException $e) {
                $this->documents->markFailed($remoteFile->id, $remoteFile->name);

                $errors[] = "{$remoteFile->name}: {$e->getMessage()}";
                $failed++;
            }
        }

        return new SyncResult(
            checked: $checked,
            synced: $synced,
            skipped: $skipped,
            failed: $failed,
            errors: $errors,
        );
    }
}
