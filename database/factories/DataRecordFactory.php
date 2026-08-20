<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DataRecord;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataRecord>
 */
class DataRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'department' => 'quality',
            'sheet_index' => 0,
            'sheet_name' => 'Sheet1',
            'row_index' => 1,
            'data' => ['date', 'value'],
        ];
    }
}
