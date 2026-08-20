<?php

declare(strict_types=1);

use App\Services\ColumnDetectionService;

/**
 * @return list<array{row_index: int, values: list<mixed>}>
 */
function makeDefectRows(int $count): array
{
    $areas = ['Assembly', 'Packing', 'Welding', 'Painting'];
    $defects = ['كسر', 'قطع', 'فلاووط', 'خدش'];
    $descriptions = ['اثناء التجميع', 'اثناء النقل', 'قبل التغليف'];
    $codes = ['FGM', 'RM', 'WIP'];

    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $day = str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT);

        $rows[] = [
            'row_index' => 3517 + $i,
            'values' => [
                "{$day}/07/26",
                (string) (1000000000 + $i),
                '7',
                (string) (900000000 + $i),
                $areas[$i % count($areas)],
                (string) (300000000 + $i),
                '...',
                (($i * 37) % 250) + 1,
                $defects[$i % count($defects)],
                $descriptions[$i % count($descriptions)],
                $codes[$i % count($codes)],
            ],
        ];
    }

    return $rows;
}

test('detectColumns correctly identifies date, area, defect, and quantity from value patterns alone, across realistic multi-row data', function () {
    $rows = makeDefectRows(60);

    $columns = (new ColumnDetectionService)->detectColumns($rows);

    expect($columns)->toEqual([
        'date_index' => 0,
        'quantity_index' => 7,
        'area_index' => 4,
        'defect_index' => 8,
    ]);
});

test('normalizeRows maps raw rows into structured records using the detected columns', function () {
    $rows = makeDefectRows(10);
    $service = new ColumnDetectionService;

    $columns = $service->detectColumns($rows);
    $normalized = $service->normalizeRows($rows, $columns);

    expect($normalized)->toHaveCount(10)
        ->and($normalized[0])->toEqual([
            'date' => '01/07/26',
            'area' => 'Assembly',
            'defect' => 'كسر',
            'quantity' => 1,
        ]);
});

test('detectColumns returns all nulls for an empty row set, without error', function () {
    $columns = (new ColumnDetectionService)->detectColumns([]);

    expect($columns)->toEqual([
        'date_index' => null,
        'area_index' => null,
        'defect_index' => null,
        'quantity_index' => null,
    ]);
});

test('detects an Excel serial-number date column, not just DD/MM/YY strings', function () {
    $rows = [];

    for ($i = 0; $i < 30; $i++) {
        $rows[] = [
            'row_index' => $i,
            'values' => [45292 + $i, 'Assembly', 'كسر', ($i % 50) + 1],
        ];
    }

    $columns = (new ColumnDetectionService)->detectColumns($rows);

    expect($columns['date_index'])->toBe(0);
});

test('does not confuse a large ID-number column with the quantity column', function () {
    $rows = [];

    for ($i = 0; $i < 30; $i++) {
        $rows[] = [
            'row_index' => $i,
            'values' => ['01/07/26', (string) (1000000000 + $i), 'Assembly', 'كسر', ($i % 100) + 1],
        ];
    }

    $columns = (new ColumnDetectionService)->detectColumns($rows);

    expect($columns['quantity_index'])->toBe(4)
        ->and($columns['quantity_index'])->not->toBe(1);
});

test('a column with only one distinct value is never picked as area or defect', function () {
    $rows = [];

    for ($i = 0; $i < 30; $i++) {
        $rows[] = [
            'row_index' => $i,
            'values' => ['01/07/26', 'FixedPlantCode', 'كسر', ($i % 50) + 1],
        ];
    }

    $columns = (new ColumnDetectionService)->detectColumns($rows);

    // Index 1 is constant across every row - it must never win the area
    // slot just because a single repeated value is technically "low
    // cardinality".
    expect($columns['area_index'])->not->toBe(1);
});

test('handles messy data - nulls, missing cells, empty strings - without crashing or misdetecting', function () {
    $areas = ['Assembly', 'Packing', 'Welding'];
    $defects = ['كسر', 'قطع', 'فلاووط'];
    $rows = [];

    for ($i = 0; $i < 20; $i++) {
        $rows[] = [
            'row_index' => $i,
            'values' => [
                $i % 5 === 0 ? null : sprintf('%02d/07/26', ($i % 28) + 1),
                null,
                $i % 3 === 0 ? '' : (string) (900000000 + $i),
                $areas[$i % 3],
                ($i % 100) + 1,
                $i % 4 === 0 ? null : $defects[$i % 3],
            ],
        ];
    }

    $service = new ColumnDetectionService;
    $columns = $service->detectColumns($rows);

    expect($columns)->toEqual([
        'date_index' => 0,
        'quantity_index' => 4,
        'area_index' => 3,
        'defect_index' => 5,
    ]);

    $normalized = $service->normalizeRows($rows, $columns);
    expect($normalized)->toHaveCount(20)
        ->and($normalized[0]['date'])->toBeNull(); // row 0: date cell was null
});

test('handles ragged row lengths (a column present in only some rows) without error', function () {
    $areas = ['Assembly', 'Packing', 'Welding'];
    $defects = ['كسر', 'قطع', 'فلاووط'];
    $rows = [];

    for ($i = 0; $i < 15; $i++) {
        $values = [sprintf('%02d/07/26', ($i % 28) + 1), $areas[$i % 3], $defects[$i % 3]];

        if ($i % 2 === 0) {
            $values[] = ($i % 50) + 1;
        }

        $rows[] = ['row_index' => $i, 'values' => $values];
    }

    $columns = (new ColumnDetectionService)->detectColumns($rows);

    expect($columns)->toEqual([
        'date_index' => 0,
        'quantity_index' => 3,
        'area_index' => 1,
        'defect_index' => 2,
    ]);
});

test('normalizeRows never drops a row, even when a category was not confidently detected', function () {
    // No column here has enough distinct values/rows to be detected as
    // anything - every category should come back null, and every row
    // should still produce a record (all fields null), not be skipped.
    $rows = [
        ['row_index' => 1, 'values' => ['x']],
        ['row_index' => 2, 'values' => ['y']],
    ];

    $service = new ColumnDetectionService;
    $columns = $service->detectColumns($rows);
    $normalized = $service->normalizeRows($rows, $columns);

    expect($normalized)->toHaveCount(2)
        ->and($normalized[0])->toEqual(['date' => null, 'area' => null, 'defect' => null, 'quantity' => null]);
});
