<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ExcelFileProvider;
use App\DTOs\SharePointExcelFile;
use App\Exceptions\SharePointException;
use App\ValueObjects\ConnectionStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Excel-specific view over the configured SharePoint drive: lists only
 * Excel files (filtering out folders and every other file type) and
 * returns them as SharePointExcelFile DTOs, never raw Graph JSON.
 *
 * Built on MicrosoftGraphClient, which it depends on directly - Graph is
 * the only supported document source for now, so no further abstraction
 * between them is needed.
 */
final class SharePointExcelService implements ExcelFileProvider
{
    /**
     * @var list<string>
     */
    private const array EXCEL_EXTENSIONS = ['xlsx', 'xls'];

    public function __construct(
        private readonly MicrosoftGraphClient $graphClient,
    ) {}

    public function healthCheck(): ConnectionStatus
    {
        return $this->graphClient->healthCheck();
    }

    /**
     * @return array<int, SharePointExcelFile>
     */
    public function listExcelFiles(): array
    {
        $files = [];

        foreach ($this->graphClient->listChildren() as $item) {
            if ($this->isExcelFile($item)) {
                $files[] = $this->toDto($item);
            }
        }

        return $files;
    }

    /**
     * Download one file's content, logging site/drive/document IDs,
     * duration, and size - never the file's own content or any secret.
     */
    public function downloadFile(string $fileId): string
    {
        $startedAt = microtime(true);

        $content = $this->graphClient->downloadContent($fileId);

        // Resolution already happened (and was memoized) inside
        // downloadContent() above, so these two calls cost no extra
        // Graph requests.
        Log::channel((string) config('chatbot.log_channel'))->info('Downloaded SharePoint Excel file', [
            'site_id' => $this->graphClient->resolveSiteId(),
            'drive_id' => $this->graphClient->resolveDriveId(),
            'document_id' => $fileId,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'size_bytes' => strlen($content),
        ]);

        return $content;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isExcelFile(array $item): bool
    {
        // Graph marks non-folder items with a "file" facet; folders carry
        // a "folder" facet instead. Skip anything that isn't a plain file.
        if (! isset($item['file'], $item['name']) || ! is_string($item['name'])) {
            return false;
        }

        $extension = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));

        return in_array($extension, self::EXCEL_EXTENSIONS, true);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toDto(array $item): SharePointExcelFile
    {
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;
        $modifiedAt = $item['lastModifiedDateTime'] ?? null;
        $size = $item['size'] ?? null;

        if (! is_string($id) || ! is_string($name) || ! is_string($modifiedAt) || ! is_int($size)) {
            throw new SharePointException('Microsoft Graph returned an incomplete file record.');
        }

        return new SharePointExcelFile(
            id: $id,
            name: $name,
            modifiedAt: new DateTimeImmutable($modifiedAt),
            size: $size,
        );
    }
}
