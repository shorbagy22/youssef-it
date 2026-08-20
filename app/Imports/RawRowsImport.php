<?php

declare(strict_types=1);

namespace App\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Row;

/**
 * Streams a spreadsheet one sheet, one row at a time, calling $onRow for
 * each row instead of building an in-memory array of the whole file.
 * Never holds more than one CHUNK of one sheet's cells in memory at
 * once, and no heading row is assumed - just raw {sheetIndex, sheetName,
 * rowIndex, values} per row, in original order.
 *
 * Deliberately bypasses maatwebsite/excel's Concerns (ToCollection,
 * WithMultipleSheets, OnEachRow) here and talks to PhpSpreadsheet's
 * IOFactory directly: WithMultipleSheets requires knowing the sheet
 * count/names ahead of time to build its sheets() array, which conflicts
 * with "any file, unknown sheet count".
 *
 * Reads each sheet in CHUNK_SIZE-row chunks, via repeated bounded
 * load() calls, rather than one load() per sheet - this is what makes
 * the early-stop below actually save memory, not just cosmetically trim
 * output after the fact. A single load() constructs cells for its whole
 * requested range EAGERLY, before any row is ever iterated (a real
 * mistake made and caught earlier while building this: bounding a row's
 * CellIterator, or even the RowIterator's end argument, does NOT stop
 * PhpSpreadsheet from constructing cells beyond it during load() - only
 * a chunked load() with a genuinely narrower requested range does). So
 * stopping mid-sheet actually means: stop requesting further CHUNKS,
 * not just stop reading further rows from an already-fully-loaded sheet.
 *
 * MAX_CONSECUTIVE_EMPTY_ROWS is the real fix for a failure mode a fixed
 * row-count cap could never safely solve: a genuine user file had a
 * sheet whose real data ended at row 2,396, but whose raw <sheetData>
 * XML declared cell elements out past row 51,369 (almost certainly
 * formatting once applied across entire rows, with zero real values
 * anywhere past the real content). A fixed row-count cap can't tell
 * "50,000 real rows" apart from "50,000 phantom rows" - they look
 * identical in scale. A long run of CONSECUTIVE fully-empty rows is a
 * much better signal: legitimate data essentially never has 50 blank
 * rows in a row in the middle of real content, while a corrupted used
 * range produces exactly that. Once MAX_CONSECUTIVE_EMPTY_ROWS is seen
 * in a row, the sheet is treated as finished - no further chunks are
 * requested. A single ISOLATED blank row (not part of such a run) is
 * still emitted via $onRow like any other row - nothing about genuinely
 * sparse real data is skipped.
 *
 * MAX_CHUNKS_PER_SHEET is a last-resort backstop only, not the primary
 * defense - it exists in case a file somehow never produces a long
 * enough empty run (e.g. real data legitimately alternates with single
 * blank rows the whole way through) so a read can never run away
 * unbounded even in that case.
 *
 * No legitimate hand-built data table has anywhere near 100 real
 * columns, so MAX_COLUMNS_PER_ROW is a safe bound with no realistic
 * data-loss risk, enforced the same way (a ReadFilter, not a post-hoc
 * iteration bound). Each row is additionally trimmed of trailing null
 * cells (see rowValues()) - a row with real data through column E
 * doesn't carry 95 uninformative trailing nulls just because some OTHER
 * row in the sheet has data further out; only trailing nulls are
 * trimmed, never a gap in the middle of a row's real content.
 */
final class RawRowsImport implements RowStreamer
{
    private const int MAX_COLUMNS_PER_ROW = 100;

    private const int CHUNK_SIZE = 2000;

    private const int MAX_CONSECUTIVE_EMPTY_ROWS = 50;

    private const int MAX_CHUNKS_PER_SHEET = 50;

    /**
     * @param  callable(int $sheetIndex, string $sheetName, int $rowIndex, list<mixed> $values): void  $onRow
     */
    public function stream(string $path, callable $onRow): void
    {
        $reader = IOFactory::createReaderForFile($path);
        $sheetNames = $this->listSheetNames($reader, $path);

        foreach ($sheetNames as $sheetIndex => $sheetName) {
            $this->streamSheet($path, $sheetIndex, $sheetName, $onRow);
        }
    }

    /**
     * Not every reader supports listWorksheetNames() (confirmed: the Csv
     * reader doesn't, since a CSV only ever has one implicit sheet) - for
     * those, fall back to one normal load just to read the sheet name(s)
     * and release it immediately.
     *
     * @return list<string>
     */
    private function listSheetNames(IReader $reader, string $path): array
    {
        if (method_exists($reader, 'listWorksheetNames')) {
            return $reader->listWorksheetNames($path);
        }

        $spreadsheet = $reader->load($path);
        $names = $spreadsheet->getSheetNames();
        $spreadsheet->disconnectWorksheets();

        return $names;
    }

    /**
     * @param  callable(int $sheetIndex, string $sheetName, int $rowIndex, list<mixed> $values): void  $onRow
     */
    private function streamSheet(string $path, int $sheetIndex, string $sheetName, callable $onRow): void
    {
        $rowIndex = 0;
        $consecutiveEmptyRows = 0;

        for ($chunk = 0; $chunk < self::MAX_CHUNKS_PER_SHEET; $chunk++) {
            $startRow = $chunk * self::CHUNK_SIZE + 1;
            $endRow = $startRow + self::CHUNK_SIZE - 1;

            $spreadsheet = $this->loadChunk($path, $sheetName, $startRow, $endRow);
            $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getSheet(0);

            // Bounded by the sheet's own highest row within this chunk's
            // filtered range - avoids wastefully iterating thousands of
            // guaranteed-nonexistent row slots for a small file (a real
            // mistake caught while building this), while still walking
            // every genuinely-declared row, blank or not, since those DO
            // get constructed even when value-less (that's the whole
            // reason this class exists).
            $chunkHighestRow = min($endRow, $sheet->getHighestRow());
            $sawAnyRow = false;
            $stop = false;

            if ($chunkHighestRow >= $startRow) {
                foreach ($sheet->getRowIterator($startRow, $chunkHighestRow) as $row) {
                    $sawAnyRow = true;
                    $values = $this->rowValues($row);
                    $consecutiveEmptyRows = $values === [] ? $consecutiveEmptyRows + 1 : 0;

                    $rowIndex++;
                    $onRow($sheetIndex, $sheetName, $rowIndex, $values);

                    if ($consecutiveEmptyRows >= self::MAX_CONSECUTIVE_EMPTY_ROWS) {
                        $stop = true;
                        break;
                    }
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $sheet);

            if ($stop || ! $sawAnyRow) {
                break;
            }
        }
    }

    private function loadChunk(string $path, string $sheetName, int $startRow, int $endRow): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        // Skip styles/formatting entirely - only raw values are needed,
        // and this meaningfully shrinks the object graph PhpSpreadsheet
        // has to build for a heavily-formatted real-world file. Chart
        // objects are unrelated to cell data and skipped outright.
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly($sheetName);
        if (method_exists($reader, 'setIncludeCharts')) {
            $reader->setIncludeCharts(false);
        }
        // The filter is what actually bounds cell CONSTRUCTION to this
        // chunk's row range and the column cap - see class docblock.
        $reader->setReadFilter(new class($startRow, $endRow, self::MAX_COLUMNS_PER_ROW) implements IReadFilter
        {
            public function __construct(
                private readonly int $startRow,
                private readonly int $endRow,
                private readonly int $maxColumnIndex,
            ) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= $this->startRow
                    && $row <= $this->endRow
                    && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumnIndex;
            }
        });

        return $reader->load($path);
    }

    /**
     * @return list<mixed>
     */
    private function rowValues(Row $row): array
    {
        $values = [];
        $cellIterator = $row->getCellIterator('A', Coordinate::stringFromColumnIndex(self::MAX_COLUMNS_PER_ROW));
        $cellIterator->setIterateOnlyExistingCells(false);

        foreach ($cellIterator as $cell) {
            $values[] = $this->safeValue($this->cellValue($cell));
        }

        return $this->trimTrailingEmptyColumns($values);
    }

    /**
     * The literal text Excel's own formula engine writes as a cached
     * result when a formula fails to evaluate - a #REF! after a
     * referenced sheet/range was deleted is the specific one confirmed
     * in this app's real data (SyncSourcesAction's "area scrap" source:
     * 3,592 of 30,867 rows, ~11.6%, carry a cached "#REF!" in a broken
     * VLOOKUP column), but any of these can appear the same way for the
     * same underlying reason - a formula that USED to resolve, in a
     * file that's since had a sheet/range/name removed out from under it.
     *
     * @var list<string>
     */
    private const array EXCEL_FORMULA_ERRORS = [
        '#REF!', '#N/A', '#VALUE!', '#DIV/0!', '#NAME?', '#NULL!', '#NUM!', '#SPILL!', '#CALC!', '#GETTING_DATA',
    ];

    /**
     * For a formula cell, prefers the CACHED result Excel itself last
     * computed and saved into the file (the <v> element stored alongside
     * a formula's <f> element in the XML) over the raw formula text -
     * getOldCalculatedValue() reads that cached value directly from what
     * was already parsed during load(), and critically never invokes
     * PhpSpreadsheet's own calculation engine, so it carries none of the
     * crash risk getCalculatedValue() does (a real, confirmed failure:
     * this app's calculation engine couldn't resolve an Excel structured
     * table reference and crashed the whole sync - see class docblock).
     *
     * This matters beyond just "nicer output": a sheet built mostly from
     * formulas (a report/rollup tab, not raw source data) previously sent
     * the AI literal formula definitions like
     * "=SUMIFS(Data!$I:$I,Data!$C:$C,A4,...)" instead of the numbers
     * those formulas produce - the AI would end up describing the
     * spreadsheet's formula LOGIC back to the user instead of answering
     * with real figures, since raw formula text was genuinely all it had
     * been given for that sheet.
     *
     * A cached value that IS one of EXCEL_FORMULA_ERRORS is normalized to
     * null rather than passed through as-is - unlike a genuinely computed
     * result, "#REF!" is not data, it's Excel's own "this formula is
     * broken" signal (confirmed directly against this app's real file -
     * see EXCEL_FORMULA_ERRORS's docblock). Sending the literal string
     * "#REF!" to the AI as if it were a real cell value is actively
     * misleading, not "preserving the data as-is" - there is no data
     * there to preserve, only a record that a formula failed to run.
     * Treating it as null is consistent with how every other genuinely
     * empty cell is already handled, not a new exception to "never alter
     * real content" - there was never real content in this cell to begin
     * with, only Excel's error marker for its absence.
     *
     * Falls back to the raw formula string only if no cached value was
     * present in the file at all (e.g. a formula that was never
     * calculated/saved by Excel) - still never actively recomputed.
     */
    private function cellValue(Cell $cell): mixed
    {
        if (! $cell->isFormula()) {
            return $cell->getValue();
        }

        $cached = $cell->getOldCalculatedValue();

        if (is_string($cached) && in_array($cached, self::EXCEL_FORMULA_ERRORS, true)) {
            return null;
        }

        return $cached !== null ? $cached : $cell->getValue();
    }

    /**
     * Drops trailing null cells from the END of a row only - never a gap
     * in the middle of real content. A row that trims down to nothing
     * (an empty array) is what counts as "empty" for the consecutive-
     * empty-row check above.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function trimTrailingEmptyColumns(array $values): array
    {
        for ($i = count($values) - 1; $i >= 0; $i--) {
            if ($values[$i] !== null) {
                return array_slice($values, 0, $i + 1);
            }
        }

        return [];
    }

    /**
     * The only transformation applied to any cell: repairing invalid byte
     * sequences so the value is safe to json_encode() later. A technical
     * safety net, not interpretation.
     */
    private function safeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
