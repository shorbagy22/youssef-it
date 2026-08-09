<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One configured data source for a department: either a local Excel file
 * already synced to this server, or a URL to download one from. Read by
 * SyncSourcesAction, which populates DataRecord from whichever file this
 * points at.
 *
 * @property int $id
 * @property string $department
 * @property string $name
 * @property string $type
 * @property string|null $file_path
 * @property string|null $url
 * @property Carbon|null $last_synced_at
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department',
        'name',
        'type',
        'file_path',
        'url',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }
}
