<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Services\ChatDataService;

test('buildPrompt notes when no data records are available', function () {
    $service = new ChatDataService;

    $prompt = $service->buildPrompt(collect(), 'What is NRFT?');

    expect($prompt)->toContain('No data records are available for this department yet.')
        ->and($prompt)->toContain('Question: What is NRFT?');
});

test('buildPrompt includes date, nrft, ppm, and defects for each record', function () {
    $service = new ChatDataService;

    $record = new DataRecord([
        'department' => 'quality',
        'date' => '2026-05-01',
        'nrft' => 95.5,
        'ppm' => 120,
        'defects' => ['scratch', 'dent'],
    ]);

    $prompt = $service->buildPrompt(collect([$record]), 'What is NRFT in May?');

    expect($prompt)->toContain('2026-05-01')
        ->and($prompt)->toContain('NRFT=95.50')
        ->and($prompt)->toContain('PPM=120.00')
        ->and($prompt)->toContain('Defects=scratch, dent')
        ->and($prompt)->toContain('Question: What is NRFT in May?');
});

test('buildPrompt shows "none" for a record with no defects', function () {
    $service = new ChatDataService;

    $record = new DataRecord([
        'department' => 'quality',
        'date' => '2026-05-01',
        'nrft' => 95.5,
        'ppm' => 120,
        'defects' => [],
    ]);

    $prompt = $service->buildPrompt(collect([$record]), 'Any defects?');

    expect($prompt)->toContain('Defects=none');
});
