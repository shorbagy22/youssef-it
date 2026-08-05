<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\SharePointExcelFile;
use App\Exceptions\SharePointException;
use App\ValueObjects\ConnectionStatus;

/**
 * Behavior contract for a source of Excel files.
 *
 * SyncSharePointExcelFilesAction depends on this interface, never on the
 * concrete SharePointExcelService - the Dependency Inversion seam that
 * lets the underlying source be swapped (or mocked in tests) without
 * touching sync logic. Bound to SharePointExcelService in
 * AppServiceProvider.
 */
interface ExcelFileProvider
{
    /**
     * Whether the document source is configured and reachable. Returns
     * ConnectionStatus::NotConfigured (never throws) when required setup
     * - e.g. a site URL - is missing.
     */
    public function healthCheck(): ConnectionStatus;

    /**
     * List every Excel file currently available, metadata only - no file
     * content is downloaded.
     *
     * @return array<int, SharePointExcelFile>
     *
     * @throws SharePointException if the source cannot be reached.
     */
    public function listExcelFiles(): array;

    /**
     * Download one file's raw content.
     *
     * @throws SharePointException if the source cannot be reached or the
     *                             file cannot be downloaded.
     */
    public function downloadFile(string $fileId): string;
}
