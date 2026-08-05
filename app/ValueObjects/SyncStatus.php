<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * The sync state of a single SharePoint Excel file, tracked on
 * SyncedDocument::$sync_status.
 *
 * A backed enum rather than a plain string keeps every possible state
 * exhaustively listed and type-checked, matching the ConnectionStatus
 * pattern already used for the dashboard's status cards.
 */
enum SyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
}
