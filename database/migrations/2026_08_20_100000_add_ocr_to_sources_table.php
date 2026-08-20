<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Opt-in per PDF source: route it through OcrPdfTextImport
            // instead of PdfTextImport (see that class's docblock). Only
            // meaningful for a file-type PDF source - SyncSourcesAction
            // ignores it for anything else.
            $table->boolean('ocr')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('ocr');
        });
    }
};
