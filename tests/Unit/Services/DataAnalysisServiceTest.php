<?php

declare(strict_types=1);

use App\Exceptions\AIResponseInvalidException;
use App\Models\DataRecord;
use App\Services\DataAnalysisService;

test('buildPrompt includes the fixed system prompt and the raw row data', function () {
    $record = DataRecord::factory()->make(['sheet_name' => 'Data', 'row_index' => 7, 'data' => ['Contamination', 12]]);

    $prompt = (new DataAnalysisService)->buildPrompt(collect([$record]));

    expect($prompt)->toContain('STRICT data extraction and analysis engine')
        ->toContain('DETECT CORRUPTION FIRST')
        ->toContain('"status": "corrupted"')
        ->toContain('"status": "ok"')
        ->toContain('Cannot answer from the provided data.')
        ->toContain('"row_index": 7');
});

test('buildPrompt appends the QUESTION section when a question is given', function () {
    $prompt = (new DataAnalysisService)->buildPrompt(collect(), 'What is the total defect count?');

    expect($prompt)->toContain("QUESTION:\nWhat is the total defect count?");
});

test('buildPrompt omits the QUESTION section when no question is given', function () {
    $prompt = (new DataAnalysisService)->buildPrompt(collect());

    expect($prompt)->not->toContain('QUESTION:');
});

test('buildPrompt omits the QUESTION section for a blank question string', function () {
    $prompt = (new DataAnalysisService)->buildPrompt(collect(), '   ');

    expect($prompt)->not->toContain('QUESTION:');
});

test('parseResponse decodes a plain JSON reply', function () {
    $result = (new DataAnalysisService)->parseResponse('{"status":"ok","analysis":{}}');

    expect($result)->toEqual(['status' => 'ok', 'analysis' => []]);
});

test('parseResponse strips a markdown code fence before decoding', function () {
    $result = (new DataAnalysisService)->parseResponse("```json\n{\"status\":\"corrupted\"}\n```");

    expect($result)->toEqual(['status' => 'corrupted']);
});

test('parseResponse throws when the reply is not valid JSON even after stripping a fence', function () {
    expect(fn () => (new DataAnalysisService)->parseResponse('Sure, here is your analysis.'))
        ->toThrow(AIResponseInvalidException::class);
});
