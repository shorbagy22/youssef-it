<?php

declare(strict_types=1);

namespace App\Imports;

/**
 * The contract SyncSourcesAction dispatches through, regardless of what
 * kind of file a Source actually is - RawRowsImport (Excel/CSV) and
 * PdfTextImport (PDF) both stream "rows" through the same callback
 * shape, so SyncSourcesAction's row-buffering/chunked-insert logic
 * doesn't need to know or care which one it's talking to.
 *
 * For a PDF, there's no real sheet/row - PdfTextImport reports
 * sheetIndex=0, sheetName="pdf", and rowIndex as the extracted line
 * number, with $values shaped as ['line' => N, 'text' => '...'] rather
 * than a flat list of spreadsheet cell values. Consumers (ChatDataService,
 * the AI prompt) already treat a DataRecord's data as an opaque
 * structure they don't interpret, so this difference in shape doesn't
 * need any special-casing downstream.
 */
interface RowStreamer
{
    /**
     * @param  callable(int $sheetIndex, string $sheetName, int $rowIndex, array<int|string, mixed> $values): void  $onRow
     */
    public function stream(string $path, callable $onRow): void;
}
