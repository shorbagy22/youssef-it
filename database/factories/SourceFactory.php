<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->words(3, true),
            'type' => 'file',
            'file_path' => 'D:\\data\\'.fake()->word().'\\report.xlsx',
            'url' => null,
            'last_synced_at' => null,
        ];
    }

    public function url(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'url',
            'file_path' => null,
            'url' => fake()->url(),
        ]);
    }
}
