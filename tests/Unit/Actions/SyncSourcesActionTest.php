<?php

declare(strict_types=1);

use App\Actions\SyncSourcesAction;
use App\Models\DataRecord;
use App\Models\Department;
use App\Models\Source;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * @param  array<int, array{0: string, 1: float, 2: float, 3: string}>  $rows
 */
function writeTestExcelFile(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    foreach ($rows as $rowIndex => $row) {
        foreach ($row as $colIndex => $value) {
            $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 1, $value);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($path);

    return $path;
}

afterEach(function () {
    // Clean up any temp files this test file itself created directly
    // (SyncSourcesAction cleans up its own url-type downloads already).
    foreach (glob(sys_get_temp_dir().'/test_source_*') as $leftover) {
        @unlink($leftover);
    }
});

test('sync parses a local Excel file into data_records', function () {
    $path = writeTestExcelFile([
        ['date', 'nrft', 'ppm', 'defects'],
        ['2026-05-01', 95.5, 120, 'scratch,dent'],
        ['2026-05-02', 97.0, 80, ''],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
        'url' => null,
    ]);

    $action = new SyncSourcesAction;
    $count = $action->sync($source);

    expect($count)->toBe(2);

    $first = DataRecord::query()->where('department', 'quality')->whereDate('date', '2026-05-01')->first();
    expect($first)->not->toBeNull()
        ->and((float) $first->nrft)->toBe(95.5)
        ->and((float) $first->ppm)->toBe(120.0)
        ->and($first->defects)->toBe(['scratch', 'dent']);

    $second = DataRecord::query()->where('department', 'quality')->whereDate('date', '2026-05-02')->first();
    expect($second->defects)->toBe([]);

    expect($source->fresh()->last_synced_at)->not->toBeNull();

    @unlink($path);
});

test('sync upserts by department and date on a second run', function () {
    $path = writeTestExcelFile([
        ['date', 'nrft', 'ppm', 'defects'],
        ['2026-05-01', 95.5, 120, 'scratch'],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $action = new SyncSourcesAction;
    $action->sync($source);
    $action->sync($source);

    expect(DataRecord::query()->count())->toBe(1);

    @unlink($path);
});

test('sync skips blank rows', function () {
    $path = writeTestExcelFile([
        ['date', 'nrft', 'ppm', 'defects'],
        ['2026-05-01', 95.5, 120, 'scratch'],
        ['', '', '', ''],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $action = new SyncSourcesAction;
    $count = $action->sync($source);

    expect($count)->toBe(1);

    @unlink($path);
});

test('sync throws when the local file does not exist', function () {
    $source = Source::factory()->create([
        'type' => 'file',
        'file_path' => 'D:\\data\\missing\\report.xlsx',
    ]);

    $action = new SyncSourcesAction;

    expect(fn () => $action->sync($source))
        ->toThrow(RuntimeException::class);
});

test('sync downloads and parses a url-type source, then removes the temp file', function () {
    $path = writeTestExcelFile([
        ['date', 'nrft', 'ppm', 'defects'],
        ['2026-05-01', 95.5, 120, 'scratch'],
    ]);
    $bytes = file_get_contents($path);
    @unlink($path);

    Http::fake([
        'example.test/report.xlsx' => Http::response($bytes, 200),
    ]);

    $source = Source::factory()->url()->create([
        'department_id' => Department::where('slug', 'safety')->value('id'),
        'url' => 'https://example.test/report.xlsx',
    ]);

    $action = new SyncSourcesAction;
    $count = $action->sync($source);

    expect($count)->toBe(1)
        ->and(DataRecord::query()->where('department', 'safety')->exists())->toBeTrue();
});

test('sync throws when the url download fails', function () {
    Http::fake([
        'example.test/report.xlsx' => Http::response('Not Found', 404),
    ]);

    $source = Source::factory()->url()->create([
        'url' => 'https://example.test/report.xlsx',
    ]);

    $action = new SyncSourcesAction;

    expect(fn () => $action->sync($source))
        ->toThrow(RuntimeException::class);
});
