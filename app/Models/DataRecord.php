<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\UnicodeJsonCast;
use Database\Factories\DataRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of one sheet of one Source's spreadsheet, captured faithfully
 * by SyncSourcesAction with no interpretation - `data` is that row's raw
 * cell values in original order, nothing skipped or altered.
 *
 * One row per Excel row (not one JSON blob per source, as before) - a
 * deliberate change to keep sync memory bounded on large files: the old
 * design built one big in-memory array/JSON blob per source before
 * writing it, which doesn't scale to files with many thousands of rows
 * across many sheets. See SyncSourcesAction and RawRowsImport.
 *
 * @property int $id
 * @property int $source_id
 * @property string $department
 * @property int $sheet_index
 * @property string $sheet_name
 * @property int $row_index
 * @property list<mixed> $data
 * @property-read Source $source
 */
class DataRecord extends Model
{
    /** @use HasFactory<DataRecordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'department',
        'sheet_index',
        'sheet_name',
        'row_index',
        'data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => UnicodeJsonCast::class,
        ];
    }

    /**
     * @return BelongsTo<Source, DataRecord>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
