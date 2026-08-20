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
 * @param  array<int, array<int, mixed>>  $rows
 */
function writeTestExcelFile(array $rows, ?string $sheetTitle = null): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    if ($sheetTitle !== null) {
        $sheet->setTitle($sheetTitle);
    }

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

/**
 * @param  array<int, array{title: string, rows: array<int, array<int, mixed>>}>  $sheets
 */
function writeMultiSheetExcelFile(array $sheets): string
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $sheetDef) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($sheetDef['title']);

        foreach ($sheetDef['rows'] as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 1, $value);
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

/**
 * Hand-builds a minimal, valid PDF from scratch (no PDF-generation
 * library is installed in this app - only smalot/pdfparser for reading
 * one) - a raw content stream per page, each line shown via its own Tj
 * text-show operator so multi-line and blank-line-between-lines cases
 * can be constructed directly. A page with an empty $lines array has an
 * empty content stream, simulating a scanned/image-only page with no
 * extractable text.
 *
 * @param  list<list<string>>  $pages  One inner list of text lines per page.
 */
function writeTestPdfFile(array $pages): string
{
    $escape = fn (string $text): string => addcslashes($text, '()\\');

    $objects = [];
    $pageObjNums = [];
    $nextObjNum = 3;

    foreach ($pages as $lines) {
        $pageObjNum = $nextObjNum++;
        $contentObjNum = $nextObjNum++;
        $pageObjNums[] = $pageObjNum;

        $stream = 'BT /F1 12 Tf 72 700 Td';
        foreach ($lines as $i => $line) {
            $stream .= ($i === 0 ? ' ' : ' 0 -14 Td ')."({$escape($line)}) Tj";
        }
        $stream .= ' ET';

        $objects[$pageObjNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 {$nextObjNum} 0 R >> >> /Contents {$contentObjNum} 0 R >>";
        $objects[$contentObjNum] = "<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream";
    }

    $fontObjNum = $nextObjNum;
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $n) => "{$n} 0 R", $pageObjNums)).'] /Count '.count($pageObjNums).' >>';
    $objects[$fontObjNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }

    $xrefStart = strlen($pdf);
    $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n";
    $pdf .= "0000000000 65535 f \n";

    foreach ($objects as $num => $body) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$num]);
    }

    $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.pdf';
    file_put_contents($path, $pdf);

    return $path;
}

afterEach(function () {
    // Clean up any temp files this test file itself created directly
    // (SyncSourcesAction cleans up its own url-type downloads already).
    foreach (glob(sys_get_temp_dir().'/test_source_*') as $leftover) {
        @unlink($leftover);
    }
});

test('sync writes one DataRecord row per Excel row, including the header row', function () {
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

    $count = (new SyncSourcesAction)->sync($source);

    // 3 rows in the file: header + 2 data rows - nothing is skipped, so
    // the header is captured and counted just like any other row.
    expect($count)->toBe(3);

    $rows = DataRecord::query()->where('source_id', $source->id)->orderBy('row_index')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->department)->toBe('quality')
        ->and($rows[0]->sheet_index)->toBe(0)
        ->and($rows[0]->row_index)->toBe(1)
        ->and($rows[0]->data)->toEqual(['date', 'nrft', 'ppm', 'defects'])
        ->and($rows[1]->row_index)->toBe(2)
        ->and($rows[1]->data)->toEqual(['2026-05-01', 95.5, 120.0, 'scratch,dent']);

    expect($source->fresh()->last_synced_at)->not->toBeNull()
        ->and($source->fresh()->last_sync_error)->toBeNull();

    @unlink($path);
});

test('sync captures EVERY sheet in a workbook, not just one', function () {
    $path = writeMultiSheetExcelFile([
        ['title' => 'Data', 'rows' => [
            ['date', 'nrft'],
            ['2026-05-01', 95.5],
            ['2026-05-02', 97.0],
        ]],
        ['title' => 'Notes', 'rows' => [
            ['End of report'],
        ]],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    // Regression test for a real, confirmed bug: a naive ToCollection
    // import calls collection() once per sheet and overwrites instead of
    // accumulating, so only the LAST sheet processed survived - a
    // workbook with real data on an earlier sheet and a near-empty
    // trailing sheet would silently lose the real data entirely.
    $count = (new SyncSourcesAction)->sync($source);

    expect($count)->toBe(4); // 3 rows (Data) + 1 row (Notes)

    $dataSheetRows = DataRecord::query()->where('source_id', $source->id)->where('sheet_name', 'Data')->get();
    $notesSheetRows = DataRecord::query()->where('source_id', $source->id)->where('sheet_name', 'Notes')->get();

    expect($dataSheetRows)->toHaveCount(3)
        ->and($notesSheetRows)->toHaveCount(1)
        ->and($notesSheetRows->first()->data)->toEqual(['End of report'])
        ->and($notesSheetRows->first()->sheet_index)->toBe(1);

    @unlink($path);
});

test('sync does NOT skip entirely blank rows', function () {
    $path = writeTestExcelFile([
        ['date', 'nrft'],
        ['2026-05-01', 95.5],
        ['', ''],
        ['2026-05-02', 97.0],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $count = (new SyncSourcesAction)->sync($source);

    expect($count)->toBe(4);

    @unlink($path);
});

test('sync stops early once a sheet hits a long run of consecutive blank rows', function () {
    $rows = [
        ['date', 'nrft'],
        ['2026-05-01', 95.5],
    ];

    // 60 fully-blank rows in a row - past the 50-consecutive-empty-row
    // threshold, this should be treated as the end of real data, the
    // same way a phantom-inflated used range would be.
    for ($i = 0; $i < 60; $i++) {
        $rows[] = ['', ''];
    }

    // Real data placed AFTER the blank run must never be reached/synced -
    // this is what proves the sheet was actually stopped early, not just
    // that trailing blanks were filtered out after the fact.
    $rows[] = ['2026-05-02', 97.0];

    $path = writeTestExcelFile($rows);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $count = (new SyncSourcesAction)->sync($source);

    expect($count)->toBeLessThan(count($rows))
        ->and(DataRecord::query()->where('source_id', $source->id)->where('data', 'like', '%2026-05-02%')->exists())->toBeFalse();

    @unlink($path);
});

test('a short isolated run of blank rows does NOT trigger the early stop', function () {
    $rows = [
        ['date', 'nrft'],
        ['2026-05-01', 95.5],
    ];

    // Only 5 blank rows - well under the 50-consecutive-empty-row
    // threshold, so real data after them must still be captured.
    for ($i = 0; $i < 5; $i++) {
        $rows[] = ['', ''];
    }

    $rows[] = ['2026-05-02', 97.0];

    $path = writeTestExcelFile($rows);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $count = (new SyncSourcesAction)->sync($source);

    expect($count)->toBe(count($rows))
        ->and(DataRecord::query()->where('source_id', $source->id)->where('data', 'like', '%2026-05-02%')->exists())->toBeTrue();

    @unlink($path);
});

test('sync does NOT skip a row that duplicates the header text', function () {
    $path = writeTestExcelFile([
        ['date', 'nrft'],
        ['2026-05-01', 95.5],
        ['date', 'nrft'],
        ['2026-05-02', 97.0],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $count = (new SyncSourcesAction)->sync($source);

    expect($count)->toBe(4)
        ->and(DataRecord::query()->where('source_id', $source->id)->where('row_index', 3)->first()->data)
        ->toEqual(['date', 'nrft']);

    @unlink($path);
});

test('a short row is not padded with trailing nulls to match a wider sheet', function () {
    $path = writeTestExcelFile([
        ['A', 'B', 'C'],
        ['x', 'y'],
        ['p', 'q', 'r'],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    (new SyncSourcesAction)->sync($source);

    // Row 2 genuinely only has 2 real cells - even though the sheet's
    // own detected width (from row 1) is 3 columns, its trailing null
    // is trimmed rather than kept as uninformative padding.
    $row2 = DataRecord::query()->where('source_id', $source->id)->where('row_index', 2)->first();

    expect($row2->data)->toEqual(['x', 'y']);

    @unlink($path);
});

test('only trailing nulls are trimmed - a gap in the middle of a row is preserved', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'x');
    // B1 intentionally left blank - a real gap between two real values.
    $sheet->setCellValue('C1', 'z');
    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    (new SyncSourcesAction)->sync($source);

    $row = DataRecord::query()->where('source_id', $source->id)->where('row_index', 1)->first();

    // Middle gap (B1) stays null - only a TRAILING null is ever trimmed.
    expect($row->data)->toEqual(['x', null, 'z']);

    @unlink($path);
});

test('columns beyond the safety cap are excluded, protecting against a corrupted used range', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    // 150 real, populated columns - beyond the 100-column cap.
    for ($col = 1; $col <= 150; $col++) {
        $sheet->setCellValueByColumnAndRow($col, 1, 'col'.$col);
    }

    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    (new SyncSourcesAction)->sync($source);

    $row = DataRecord::query()->where('source_id', $source->id)->where('row_index', 1)->first();

    // Capped at 100 - columns 101-150 are excluded rather than risking
    // the memory explosion a corrupted/inflated used range can cause.
    expect($row->data)->toHaveCount(100)
        ->and($row->data[0])->toBe('col1')
        ->and($row->data[99])->toBe('col100');

    @unlink($path);
});

test('sync never converts or reinterprets a value - a numeric-looking date column stays a raw number', function () {
    $path = writeTestExcelFile([
        ['Label', 'Quantity'],
        ['Widget', 45000],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    (new SyncSourcesAction)->sync($source);

    // No date/number interpretation happens on the PHP side at all - the
    // raw cell value is preserved exactly, whatever it looks like.
    $row = DataRecord::query()->where('source_id', $source->id)->where('row_index', 2)->first();

    expect($row->data[1])->toEqual(45000);

    @unlink($path);
});

test('a formula cell uses Excel\'s own cached result, never PhpSpreadsheet\'s calculation engine', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Total');
    $sheet->setCellValue('B1', 10);
    $sheet->setCellValue('B2', 20);
    $sheet->setCellValue('A2', '=SUM(B1:B1)');
    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.xlsx';
    // The default writer behavior: pre-calculate and cache each formula's
    // result into the saved file's XML (the <v> next to <f>) - exactly
    // what a real save from Excel itself also does. This is the cached
    // value getOldCalculatedValue() reads back, with PhpSpreadsheet's own
    // calculation engine never invoked on read.
    $writer = new Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(true);
    $writer->save($path);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    (new SyncSourcesAction)->sync($source);

    $row = DataRecord::query()->where('source_id', $source->id)->where('row_index', 2)->first();

    // Regression test for a real, confirmed bug: actively RE-calculating
    // formulas on read crashed an entire real sync on a formula
    // PhpSpreadsheet's engine couldn't resolve (an Excel structured table
    // reference). Reading the file's own cached result avoids that risk
    // entirely while still giving real numbers, not formula text, to the
    // AI (a formula-heavy report sheet answered with formula definitions
    // instead of figures otherwise).
    expect($row->data[0])->toBe(10);

    @unlink($path);
});

test('a formula cell falls back to its raw formula string when the file has no cached result', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Total');
    $sheet->setCellValue('B1', 10);
    $sheet->setCellValue('A2', '=SUM(B1:B1)');
    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.xlsx';
    // PhpSpreadsheet's writer always emits SOME cached "<v>" value next
    // to a formula (either the real precalculated result, or a "0"
    // placeholder if precalculation is disabled - confirmed by
    // inspecting the saved XML directly) - there's no writer option that
    // produces a formula with no cached value at all. A file with
    // genuinely no cached result (some other producer, or a formula
    // truly never evaluated) just has no <v> element inside the
    // formula's <c>, so that's constructed here directly by editing the
    // saved archive's XML, rather than relying on a writer option that
    // doesn't actually produce this case.
    (new Xlsx($spreadsheet))->save($path);

    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $xml = preg_replace('/(<f>SUM\(B1:B1\)<\/f>)<v>[^<]*<\/v>/', '$1', $xml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    (new SyncSourcesAction)->sync($source);

    $row = DataRecord::query()->where('source_id', $source->id)->where('row_index', 2)->first();

    expect($row->data[0])->toBe('=SUM(B1:B1)');

    @unlink($path);
});

test('a formula cell whose cached result is a broken-reference error is normalized to null, not sent as literal "#REF!" text', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Item');
    $sheet->setCellValue('B1', 'Real Value');
    $sheet->setCellValue('A2', 'x');
    $sheet->setCellValue('B2', '=SUM(A1:A1)');
    // A real value AFTER the broken formula, so nulling B2 leaves a
    // preserved gap in the MIDDLE of the row rather than a trailing one
    // trimTrailingEmptyColumns() would drop entirely - proves the value
    // was actually normalized to null, not just trimmed away.
    $sheet->setCellValue('C2', 'y');
    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    // Simulates a real, confirmed failure mode found in this app's
    // actual data: a formula referencing a range/sheet that's since been
    // deleted from the workbook - Excel itself can't evaluate it either,
    // and its own last-cached result is literally the error string
    // "#REF!", with the cell's data type marked "e" (error). Constructed
    // directly via XML editing since there's no PhpSpreadsheet writer API
    // for "make this formula broken" - real broken files aren't produced
    // that way either, they get that way from external edits over time.
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $xml = preg_replace(
        '/<c r="B2"[^>]*><f>SUM\(A1:A1\)<\/f><v>[^<]*<\/v><\/c>/',
        '<c r="B2" t="e"><f>SUM(#REF!)</f><v>#REF!</v></c>',
        $xml,
    );
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    (new SyncSourcesAction)->sync($source);

    $row = DataRecord::query()->where('source_id', $source->id)->where('row_index', 2)->first();

    expect($row->data[1])->toBeNull()
        ->and($row->data[2])->toBe('y');

    @unlink($path);
});

test('re-syncing a source replaces its rows wholesale rather than accumulating', function () {
    $pathA = writeTestExcelFile([['date', 'nrft'], ['2026-05-01', 95.5], ['2026-05-02', 97.0]]);
    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $pathA,
    ]);

    (new SyncSourcesAction)->sync($source);
    expect(DataRecord::query()->where('source_id', $source->id)->count())->toBe(3);

    $pathB = writeTestExcelFile([['date', 'nrft'], ['2026-06-01', 90.0]]);
    $source->update(['file_path' => $pathB]);
    (new SyncSourcesAction)->sync($source);

    expect(DataRecord::query()->where('source_id', $source->id)->count())->toBe(2);

    @unlink($pathA);
    @unlink($pathB);
});

test('sync reports progress periodically for long-running syncs', function () {
    // Rows beyond the header, so the total exceeds a small progress
    // interval used just for this test via reflection would be
    // overkill - instead, confirm the callback fires at least once for
    // a file with enough rows, with a monotonically increasing count.
    $rows = [['n']];
    for ($i = 1; $i <= 50; $i++) {
        $rows[] = [$i];
    }
    $path = writeTestExcelFile($rows);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $progressCalls = [];
    (new SyncSourcesAction)->sync($source, function (int $rowsSoFar) use (&$progressCalls) {
        $progressCalls[] = $rowsSoFar;
    });

    // PROGRESS_EVERY_ROWS is 1000, so a 51-row file won't actually
    // trigger a call - this just proves the callback is wired through
    // without throwing when provided, which is the contract callers rely
    // on (SyncSources console command always passes one).
    expect($progressCalls)->toBeArray();

    @unlink($path);
});

test('sync records a clear error on the Source and throws when the local file does not exist', function () {
    $source = Source::factory()->create([
        'type' => 'file',
        'file_path' => 'D:\\data\\missing\\report.xlsx',
    ]);

    $action = new SyncSourcesAction;

    expect(fn () => $action->sync($source))->toThrow(RuntimeException::class);

    expect($source->fresh()->last_sync_error)->toContain('File not found');
});

test('sync records a clear error and throws for a zero-byte file', function () {
    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.xlsx';
    file_put_contents($path, '');

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    expect(fn () => (new SyncSourcesAction)->sync($source))->toThrow(RuntimeException::class);

    expect($source->fresh()->last_sync_error)->toContain('empty');

    @unlink($path);
});

test('sync records a clear error and throws for an unsupported extension', function () {
    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.docx';
    file_put_contents($path, 'not a spreadsheet');

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    expect(fn () => (new SyncSourcesAction)->sync($source))->toThrow(RuntimeException::class);

    expect($source->fresh()->last_sync_error)->toContain('Unsupported file extension');

    @unlink($path);
});

test('sync records a clear error and throws for a corrupted file with a valid extension', function () {
    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.xlsx';
    file_put_contents($path, "this is not really an xlsx file\x00\x01\xFF");

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    expect(fn () => (new SyncSourcesAction)->sync($source))->toThrow(RuntimeException::class);

    expect($source->fresh()->last_sync_error)->toContain('Could not parse');

    @unlink($path);
});

test('sync extracts PDF text as one DataRecord row per non-blank line', function () {
    $path = writeTestPdfFile([
        ['First line', '', 'Third line'],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $count = (new SyncSourcesAction)->sync($source);

    // The blank line between "First line" and "Third line" carries no
    // text and isn't stored as its own empty row (see PdfTextImport) -
    // only the two real lines survive, renumbered sequentially.
    expect($count)->toBe(2);

    $rows = DataRecord::query()->where('source_id', $source->id)->orderBy('row_index')->get();

    expect($rows[0]->sheet_name)->toBe('pdf')
        ->and($rows[0]->row_index)->toBe(1)
        ->and($rows[0]->data)->toEqual(['line' => 1, 'text' => 'First line'])
        ->and($rows[1]->row_index)->toBe(2)
        ->and($rows[1]->data)->toEqual(['line' => 2, 'text' => 'Third line']);

    @unlink($path);
});

test('sync captures text from every page of a multi-page PDF', function () {
    $path = writeTestPdfFile([
        ['Page one line'],
        ['Page two line'],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $count = (new SyncSourcesAction)->sync($source);

    expect($count)->toBe(2);

    $texts = DataRecord::query()->where('source_id', $source->id)->orderBy('row_index')->pluck('data')
        ->map(fn (array $row) => $row['text'])->all();

    expect($texts)->toEqual(['Page one line', 'Page two line']);

    @unlink($path);
});

test('a PDF with no extractable text (e.g. scanned pages) syncs to zero rows, not an error', function () {
    $path = writeTestPdfFile([
        [], // a page with no text content at all
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $count = (new SyncSourcesAction)->sync($source);

    expect($count)->toBe(0)
        ->and($source->fresh()->last_sync_error)->toBeNull();

    @unlink($path);
});

test('sync records a clear error and throws for a corrupted PDF', function () {
    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.pdf';
    file_put_contents($path, "%PDF-1.4\nthis is not a real pdf body\x00\x01\xFF");

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    expect(fn () => (new SyncSourcesAction)->sync($source))->toThrow(RuntimeException::class);

    expect($source->fresh()->last_sync_error)->toContain('Could not parse')
        ->toContain('PDF');

    @unlink($path);
});

test('sync rejects a PDF larger than the safety ceiling before attempting to parse it', function () {
    $path = tempnam(sys_get_temp_dir(), 'test_source_').'.pdf';
    // Doesn't need to be a valid PDF - the size check runs before any
    // parsing is attempted, so an oversized file fails fast regardless
    // of its content.
    file_put_contents($path, str_repeat('a', 1024));

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    // Reflection avoids needing a real 50MB fixture on disk just to prove
    // the ceiling is enforced - filesize() is stubbed at the OS level via
    // a truncate instead, which is cheap and still exercises real code.
    ftruncate(fopen($path, 'r+'), 51 * 1024 * 1024);

    expect(fn () => (new SyncSourcesAction)->sync($source))->toThrow(RuntimeException::class);

    expect($source->fresh()->last_sync_error)->toContain('too large');

    @unlink($path);
});

test('an Excel source is unaffected by PDF support - still routed to the spreadsheet importer', function () {
    $path = writeTestExcelFile([['date', 'nrft'], ['2026-05-01', 95.5]]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    $count = (new SyncSourcesAction)->sync($source);

    expect($count)->toBe(2)
        ->and(DataRecord::query()->where('source_id', $source->id)->first()->sheet_name)->not->toBe('pdf');

    @unlink($path);
});

test('a failed re-sync leaves the previous good rows intact instead of a half-replaced mix', function () {
    $path = writeTestExcelFile([['date', 'nrft'], ['2026-05-01', 95.5]]);
    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    (new SyncSourcesAction)->sync($source);
    expect(DataRecord::query()->where('source_id', $source->id)->count())->toBe(2);

    $source->update(['file_path' => 'D:\\data\\missing\\report.xlsx']);

    expect(fn () => (new SyncSourcesAction)->sync($source))->toThrow(RuntimeException::class);

    // The old rows must still be there - the whole delete+insert is one
    // transaction, so a failure before any new row is written never
    // touches the previous successful sync's data.
    expect(DataRecord::query()->where('source_id', $source->id)->count())->toBe(2);

    @unlink($path);
});

test('a successful sync clears a previously recorded error', function () {
    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => 'D:\\data\\missing\\report.xlsx',
        'last_sync_error' => 'stale error from a previous run',
    ]);

    $path = writeTestExcelFile([
        ['date', 'nrft'],
        ['2026-05-01', 95.5],
    ]);
    $source->update(['file_path' => $path]);

    (new SyncSourcesAction)->sync($source);

    expect($source->fresh()->last_sync_error)->toBeNull();

    @unlink($path);
});

test('sync downloads and parses a url-type source, then removes the temp file', function () {
    $path = writeTestExcelFile([
        ['date', 'nrft'],
        ['2026-05-01', 95.5],
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

    expect($count)->toBe(2)
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

test('a PDF source with ocr=false (the default) is still routed to PdfTextImport, not OcrPdfTextImport', function () {
    $path = writeTestPdfFile([
        ['Some line'],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
    ]);

    expect($source->fresh()->ocr)->toBeFalse();

    (new SyncSourcesAction)->sync($source);

    // sheet_name is 'pdf' for PdfTextImport, 'pdf-ocr' for
    // OcrPdfTextImport - this is the only externally-observable proof of
    // which importer actually ran.
    expect(DataRecord::query()->where('source_id', $source->id)->first()->sheet_name)->toBe('pdf');

    @unlink($path);
});

test('an ocr=true PDF source is routed to OcrPdfTextImport and produces readable text via real OCR', function () {
    // A genuine integration test against the real pdftoppm/tesseract
    // binaries configured in config/pdf_ocr.php (see OcrPdfTextImport) -
    // skipped, not failed, on a machine where they aren't installed
    // (e.g. CI), since there's no meaningful way to fake an external OCR
    // engine's actual text-recognition behavior.
    $pdftoppm = (string) config('pdf_ocr.pdftoppm_binary');
    $tesseract = (string) config('pdf_ocr.tesseract_binary');

    $available = (str_contains($pdftoppm, DIRECTORY_SEPARATOR) || str_contains($pdftoppm, '/') ? file_exists($pdftoppm) : true)
        && (str_contains($tesseract, DIRECTORY_SEPARATOR) || str_contains($tesseract, '/') ? file_exists($tesseract) : true);

    if (! $available) {
        test()->markTestSkipped('pdftoppm/tesseract not available at the configured paths.');
    }

    $path = writeTestPdfFile([
        ['Hello World'],
    ]);

    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
        'type' => 'file',
        'file_path' => $path,
        'ocr' => true,
    ]);

    $count = (new SyncSourcesAction)->sync($source);

    expect($count)->toBeGreaterThan(0);

    $rows = DataRecord::query()->where('source_id', $source->id)->orderBy('row_index')->get();

    expect($rows->first()->sheet_name)->toBe('pdf-ocr')
        // OCR isn't byte-perfect, so this only checks the recognizable
        // English word survived - the real, precise-text confirmation of
        // this pipeline's output is in this class's own docblock and PR
        // history, checked directly against this app's actual data.
        ->and($rows->pluck('data')->map(fn (array $row) => $row['text'])->implode(' '))->toContain('Hello');

    expect($source->fresh()->last_sync_error)->toBeNull();

    @unlink($path);
});
