<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synced_documents', function (Blueprint $table) {
            $table->id();
            $table->string('sharepoint_id')->unique();
            $table->string('file_name');
            $table->timestamp('modified_at')->nullable();
            $table->string('checksum')->nullable();
            $table->string('sync_status')->default('pending');
            $table->string('local_path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synced_documents');
    }
};
