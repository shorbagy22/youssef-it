<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_records', function (Blueprint $table) {
            $table->id();
            $table->string('department');
            $table->date('date');
            $table->decimal('nrft', 8, 2)->nullable();
            $table->decimal('ppm', 10, 2)->nullable();
            $table->json('defects')->nullable();
            $table->json('extra_data')->nullable();
            $table->timestamps();

            // One record per department per day - the natural grain of one
            // Excel row - and the match key SyncSourcesAction upserts on.
            $table->unique(['department', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_records');
    }
};
