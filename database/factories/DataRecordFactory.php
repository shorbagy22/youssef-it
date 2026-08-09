<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DataRecord;
use App\ValueObjects\Department;
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
            'department' => fake()->randomElement(Department::cases())->value,
            'date' => fake()->date(),
            'nrft' => fake()->randomFloat(2, 80, 100),
            'ppm' => fake()->randomFloat(2, 0, 500),
            'defects' => fake()->randomElements(['scratch', 'dent', 'misalignment', 'discoloration'], 2),
            'extra_data' => [],
        ];
    }
}
