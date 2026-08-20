<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One configured data source for a department: either a local Excel file
 * already synced to this server, or a URL to download one from. Read by
 * SyncSourcesAction, which populates DataRecord from whichever file this
 * points at.
 *
 * @property int $id
 * @property int $department_id
 * @property string $name
 * @property string $type
 * @property string|null $file_path
 * @property string|null $url
 * @property bool $ocr
 * @property Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 * @property-read Department $department
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'name',
        'type',
        'file_path',
        'url',
        'ocr',
        'last_synced_at',
        'last_sync_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocr' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Department, Source>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
