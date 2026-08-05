<?php

declare(strict_types=1);

namespace App\DTOs;

use DateTimeImmutable;

/**
 * Metadata for one Excel file as reported by Microsoft Graph, before any
 * content is downloaded.
 *
 * Built by SharePointExcelService from a raw Graph driveItem response -
 * nothing outside that service ever sees Graph's JSON shape directly.
 */
final readonly class SharePointExcelFile
{
    public function __construct(
        public string $id,
        public string $name,
        public DateTimeImmutable $modifiedAt,
        public int $size,
    ) {}
}
