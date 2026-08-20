<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Services\PdfQaService;

test('buildPrompt includes the fixed system prompt, document text, and the required question', function () {
    $record = DataRecord::factory()->make(['sheet_name' => 'pdf', 'row_index' => 3, 'data' => ['line' => 3, 'text' => 'منظمة أو شخص يمكن أن يؤثر']]);

    $prompt = (new PdfQaService)->buildPrompt(collect([$record]), 'ما الجهة المعنية؟');

    expect($prompt)->toContain('reading a PDF document with possibly broken or misordered text')
        ->toContain('You MUST search ALL content before answering')
        ->toContain('DO NOT say "not found" unless you searched everything')
        ->toContain('"row_index": 3')
        ->toContain("USER QUESTION:\nما الجهة المعنية؟")
        ->not->toContain('{{DOCUMENT_TEXT}}')
        ->not->toContain('{{QUESTION}}');
});

test('buildPrompt substitutes the document text between the fixed section markers, not appended after them', function () {
    $record = DataRecord::factory()->make(['row_index' => 1, 'data' => ['line' => 1, 'text' => 'needle text']]);

    $prompt = (new PdfQaService)->buildPrompt(collect([$record]), 'a question');

    $documentSection = strstr(strstr($prompt, 'DOCUMENT (RAW TEXT)'), 'USER QUESTION:', true);

    expect($documentSection)->toContain('needle text');
});
