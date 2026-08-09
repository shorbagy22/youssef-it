<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DataRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One department's parsed data for one day, extracted from a Source's
 * Excel file by SyncSourcesAction. Never holds raw file content - only
 * the structured numbers/facts the chat API needs, per the standing "no
 * full file sent to the AI" constraint.
 *
 * @property int $id
 * @property string $department
 * @property Carbon $date
 * @property float|null $nrft
 * @property float|null $ppm
 * @property array<int, string>|null $defects
 * @property array<string, mixed>|null $extra_data
 */
class DataRecord extends Model
{
    /** @use HasFactory<DataRecordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department',
        'date',
        'nrft',
        'ppm',
        'defects',
        'extra_data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'nrft' => 'decimal:2',
            'ppm' => 'decimal:2',
            'defects' => 'array',
            'extra_data' => 'array',
        ];
    }
}
