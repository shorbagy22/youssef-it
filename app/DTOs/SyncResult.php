<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Summary of one sync run: how many remote Excel files were seen, and
 * what happened to each.
 *
 * Returned by SyncSharePointExcelFilesAction so the console command (and
 * tests) can report an outcome without re-deriving it from the database.
 */
final readonly class SyncResult
{
    /**
     * @param  array<int, string>  $errors  One message per failed file, e.g. "invoice.xlsx: Could not reach SharePoint."
     */
    public function __construct(
        public int $checked,
        public int $synced,
        public int $skipped,
        public int $failed,
        public array $errors = [],
    ) {}
}
