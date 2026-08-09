<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('department');
            $table->string('name');
            $table->enum('type', ['file', 'url']);
            $table->string('file_path')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
