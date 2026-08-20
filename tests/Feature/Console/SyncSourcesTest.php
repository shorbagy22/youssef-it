<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Models\Department;
use App\Models\Source;

test('it syncs every source and reports a summary', function () {
    $path = writeTestExcelFile([
        ['date', 'nrft', 'ppm', 'defects'],
        ['2026-05-01', 95.5, 120, 'scratch'],
    ]);

    Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'name' => 'Quality Report',
        'type' => 'file',
        'file_path' => $path,
    ]);

    $this->artisan('sources:sync')
        ->expectsOutputToContain('Syncing 1 source(s)...')
        // 2, not 1: the header row is captured too now, nothing is skipped.
        ->expectsOutputToContain('2 record(s) synced.')
        ->expectsOutputToContain('Done. 0 failure(s).')
        ->assertExitCode(0);

    // 2, not 1: one DataRecord row per Excel row now (header + 1 data
    // row), not one JSON-blob row per source.
    expect(DataRecord::query()->count())->toBe(2);

    @unlink($path);
});

test('it continues past a source with a missing file and reports the failure', function () {
    Source::factory()->create([
        'name' => 'Broken Source',
        'type' => 'file',
        'file_path' => 'D:\\data\\missing\\report.xlsx',
    ]);

    $this->artisan('sources:sync')
        ->expectsOutputToContain('FAILED')
        ->expectsOutputToContain('Done. 1 failure(s).')
        ->assertExitCode(0);

    expect(DataRecord::query()->count())->toBe(0);
});

test('a failing source does not stop the rest of the sources from syncing', function () {
    $path = writeTestExcelFile([
        ['date', 'nrft', 'ppm', 'defects'],
        ['2026-05-01', 95.5, 120, 'scratch'],
    ]);

    Source::factory()->create([
        'name' => 'Broken Source',
        'type' => 'file',
        'file_path' => 'D:\\data\\missing\\report.xlsx',
    ]);
    Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'name' => 'Good Source',
        'type' => 'file',
        'file_path' => $path,
    ]);

    $this->artisan('sources:sync')
        ->expectsOutputToContain('Done. 1 failure(s).')
        ->assertExitCode(0);

    expect(DataRecord::query()->count())->toBe(2);

    @unlink($path);
});

test('it reports when there are no sources configured', function () {
    $this->artisan('sources:sync')
        ->expectsOutputToContain('No sources configured.')
        ->assertExitCode(0);
});
