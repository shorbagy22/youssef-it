<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\SyncedDocument;
use App\ValueObjects\SyncStatus;
use DateTimeImmutable;

/**
 * Wraps every Eloquent query against the synced_documents table, so
 * SyncSharePointExcelFilesAction never touches the model or its column
 * names directly - just this repository's intent-revealing methods.
 */
final class SyncedDocumentRepository
{
    public function findBySharePointId(string $sharePointId): ?SyncedDocument
    {
        return SyncedDocument::query()
            ->where('sharepoint_id', $sharePointId)
            ->first();
    }

    /**
     * Record a successful sync: creates the row if this is the first time
     * this file has been seen, otherwise updates it in place.
     */
    public function markSynced(
        string $sharePointId,
        string $fileName,
        DateTimeImmutable $modifiedAt,
        string $checksum,
        string $localPath,
        int $size,
    ): SyncedDocument {
        $document = $this->findBySharePointId($sharePointId) ?? new SyncedDocument([
            'sharepoint_id' => $sharePointId,
        ]);

        $document->fill([
            'file_name' => $fileName,
            'modified_at' => $modifiedAt,
            'checksum' => $checksum,
            'sync_status' => SyncStatus::Synced,
            'local_path' => $localPath,
            'size' => $size,
            'synced_at' => now(),
        ]);

        $document->save();

        return $document;
    }

    /**
     * Record a failed sync attempt. Deliberately leaves modified_at,
     * checksum, local_path, and size untouched - either they hold the
     * last successful sync's values (safe to keep), or this is a
     * never-synced file (nothing to preserve). Either way, the next sync
     * run will see a mismatch against the remote file and retry.
     */
    public function markFailed(string $sharePointId, string $fileName): SyncedDocument
    {
        $document = $this->findBySharePointId($sharePointId) ?? new SyncedDocument([
            'sharepoint_id' => $sharePointId,
        ]);

        $document->fill([
            'file_name' => $fileName,
            'sync_status' => SyncStatus::Failed,
        ]);

        $document->save();

        return $document;
    }
}
