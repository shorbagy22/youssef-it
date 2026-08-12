<?php

declare(strict_types=1);

use App\ValueObjects\Department as LegacyDepartment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Seed the four departments the app has always supported, with
        // slugs matching ValueObjects\Department's values exactly - that
        // enum is still the source of truth for /api/chat and data_records
        // (see its docblock), so Sources pointed at these seeded rows stay
        // queryable by the existing chat pipeline after this migration.
        $now = now();

        DB::table('departments')->insert(
            collect(LegacyDepartment::cases())
                ->map(fn (LegacyDepartment $department): array => [
                    'name' => ucfirst($department->value),
                    'slug' => $department->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
