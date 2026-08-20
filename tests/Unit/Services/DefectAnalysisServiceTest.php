<?php

declare(strict_types=1);

use App\Exceptions\AIResponseInvalidException;
use App\Models\DataRecord;
use App\Services\DefectAnalysisService;

test('buildPrompt includes the fixed system prompt and the raw row data', function () {
    $record = DataRecord::factory()->make(['sheet_name' => 'Data', 'row_index' => 5, 'data' => ['Contamination', 12]]);

    $prompt = (new DefectAnalysisService)->buildPrompt(collect([$record]));

    expect($prompt)->toContain('DO NOT assume first row is header')
        ->toContain('"error": "No valid defect data found"')
        ->toContain('Contamination')
        ->toContain('"row_index": 5');
});

test('parseResponse decodes a plain JSON reply', function () {
    $result = (new DefectAnalysisService)->parseResponse('{"confidence":"high","defects":[]}');

    expect($result)->toEqual(['confidence' => 'high', 'defects' => []]);
});

test('parseResponse strips a markdown code fence before decoding', function () {
    $result = (new DefectAnalysisService)->parseResponse("```json\n{\"confidence\":\"low\"}\n```");

    expect($result)->toEqual(['confidence' => 'low']);
});

test('parseResponse throws when the reply is not valid JSON even after stripping a fence', function () {
    expect(fn () => (new DefectAnalysisService)->parseResponse('Sure, here is your analysis.'))
        ->toThrow(AIResponseInvalidException::class);
});
