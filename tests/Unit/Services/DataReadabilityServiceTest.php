<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Services\DataReadabilityService;

test('buildPrompt includes the fixed system prompt and the raw row data', function () {
    $record = DataRecord::factory()->make(['sheet_name' => 'pdf', 'row_index' => 3, 'data' => ['line' => 3, 'text' => 'garbled \x00\x01 text']]);

    $prompt = (new DataReadabilityService)->buildPrompt(collect([$record]));

    expect($prompt)->toContain('Do NOT guess or invent meaning')
        ->toContain('The data appears corrupted or unreadable. Cannot extract reliable meaning.')
        ->toContain('No clear structure detected.')
        ->toContain('"row_index": 3');
});

test('buildPrompt sends an empty but valid rows array when there is nothing to check', function () {
    $prompt = (new DataReadabilityService)->buildPrompt(collect());

    expect($prompt)->toContain('"rows": []');
});
