<?php

declare(strict_types=1);

namespace App\Models;

use App\ValueObjects\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single Excel file synced from SharePoint: its identity, last known
 * remote modification time, content checksum, sync outcome, and where its
 * bytes live on local disk.
 *
 * Written and read exclusively through SyncedDocumentRepository - nothing
 * else should query this model directly, keeping persistence details out
 * of business logic (SyncSharePointExcelFilesAction).
 *
 * @property int $id
 * @property string $sharepoint_id
 * @property string $file_name
 * @property Carbon|null $modified_at
 * @property string|null $checksum
 * @property SyncStatus $sync_status
 * @property string|null $local_path
 * @property int|null $size
 * @property Carbon|null $synced_at
 */
class SyncedDocument extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'sharepoint_id',
        'file_name',
        'modified_at',
        'checksum',
        'sync_status',
        'local_path',
        'size',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modified_at' => 'datetime',
            'synced_at' => 'datetime',
            'sync_status' => SyncStatus::class,
        ];
    }
}
