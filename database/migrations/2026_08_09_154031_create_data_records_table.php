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
            // Not unique anymore: many rows per source now (one per Excel
            // row), not one JSON blob per source.
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('department');
            $table->unsignedInteger('sheet_index');
            $table->string('sheet_name');
            $table->unsignedInteger('row_index');
            $table->json('data');
            $table->timestamps();

            // Ordered retrieval/replacement per source, and per-department
            // filtering for /api/chat.
            $table->index(['source_id', 'sheet_index', 'row_index']);
            $table->index('department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_records');
    }
};
