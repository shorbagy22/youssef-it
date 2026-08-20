<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Services\DefectQueryService;

test('buildPrompt includes the fixed system prompt, appended data, and question', function () {
    $record = DataRecord::factory()->make(['sheet_name' => 'Total', 'row_index' => 3524, 'data' => [46224, ' Assembly', 'قطع', 4]]);

    $prompt = (new DefectQueryService)->buildPrompt(collect([$record]), 'what are the defects in assembly area on 21/7/2026?');

    expect($prompt)->toContain('data pipeline with TWO layers')
        ->toContain('The backend MAY send:')
        ->toContain('DO NOT assume fixed column index')
        ->toContain('STEP 5 — VALIDATION')
        ->toContain('YOU MUST return results')
        ->toContain('No defects found for the specified date and area.')
        ->toContain('If the correct date EXISTS in ANY row')
        ->toContain("QUESTION:\nwhat are the defects in assembly area on 21/7/2026?")
        ->toContain('"row_index": 3524')
        ->toContain('قطع');
});
