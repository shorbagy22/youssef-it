<?php

declare(strict_types=1);

namespace App\Actions;

use App\Imports\OcrPdfTextImport;
use App\Imports\PdfTextImport;
use App\Imports\RawRowsImport;
use App\Imports\RowStreamer;
use App\Models\DataRecord;
use App\Models\Source;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Syncs one Source's spreadsheet OR PDF into DataRecord rows - one row
 * per Excel row (or extracted PDF text line), streamed and
 * batch-inserted rather than built up as one big in-memory array, so
 * memory stays bounded regardless of file size (see RawRowsImport and
 * PdfTextImport). No structure is assumed: no header row, no column
 * types, no required fields, no fixed sheet count - every row, every
 * sheet, exactly as PhpSpreadsheet (or, for a PDF, smalot/pdfparser -
 * or Tesseract OCR, for a PDF explicitly opted into that via
 * Source::$ocr; see OcrPdfTextImport) returned it. Which importer runs
 * is decided by file extension and, for a PDF, that opt-in flag (see
 * importerFor()) - all three share the same RowStreamer contract, so
 * everything below this point (buffering, chunked inserts, progress
 * reporting, transaction handling) is identical regardless of which one
 * ran.
 *
 * The whole sync (delete old rows for this source + insert new ones) is
 * wrapped in one DB transaction, so a failure partway through leaves the
 * PREVIOUS good dataset intact rather than a half-replaced mix of old
 * and new rows. That's a deliberate tradeoff for a source of this app's
 * expected scale (thousands of rows, not millions) - a transaction held
 * open for the whole sync duration would become a real concern (lock/
 * undo-log growth) on a much larger file than anything this app has
 * seen; if that ever becomes real, this needs a different strategy
 * (e.g. write to a new batch and swap instead of delete-then-insert).
 *
 * Resolves the file (local path, or downloads a url-type source to a
 * temp file), validates it's actually readable before attempting to
 * parse, reads it with PhpSpreadsheet directly (see RawRowsImport), and
 * replaces that source's DataRecord rows wholesale. Any failure (missing
 * file, unreadable file, corrupt/unparseable content) is recorded on the
 * Source itself via last_sync_error, then re-thrown as a RuntimeException
 * so callers only ever need to catch one exception type. Called once per
 * source by the sources:sync command, which is responsible for catching
 * that and moving on to the next source - a single source's bad file
 * never aborts the whole sync run.
 */
final class SyncSourcesAction
{
    private const array SUPPORTED_EXTENSIONS = ['xlsx', 'xls', 'xlsm', 'ods', 'csv', 'pdf'];

    private const int MAX_PDF_FILE_SIZE_BYTES = 50 * 1024 * 1024;

    private const int INSERT_CHUNK_SIZE = 500;

    private const int PROGRESS_EVERY_ROWS = 1000;

    /**
     * Sync one source and return how many rows were captured across all
     * of its sheets.
     *
     * @param  (callable(int $rowsSoFar): void)|null  $onProgress  Called
     *         roughly every PROGRESS_EVERY_ROWS rows, for long-running
     *         syncs (e.g. the console command prints progress with it).
     *
     * @throws RuntimeException if the file can't be resolved, isn't a
     *                          readable non-empty file of a supported
     *                          type, or can't be parsed as a spreadsheet.
     */
    public function sync(Source $source, ?callable $onProgress = null): int
    {
        try {
            $path = $this->resolvePath($source);
            $this->validateFile($path);
        } catch (RuntimeException $e) {
            $this->recordFailure($source, $e->getMessage());

            throw $e;
        }

        $isTemporary = $source->type === 'url';

        try {
            $totalRows = $this->streamIntoDatabase($source, $path, $onProgress);

            $this->recordSuccess($source);

            return $totalRows;
        } catch (Throwable $e) {
            $this->recordFailure($source, $e->getMessage());

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), previous: $e);
        } finally {
            if ($isTemporary && file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  (callable(int $rowsSoFar): void)|null  $onProgress
     */
    private function streamIntoDatabase(Source $source, string $path, ?callable $onProgress): int
    {
        $department = $source->department->slug;
        $now = now();
        $totalRows = 0;
        $buffer = [];

        DB::transaction(function () use ($source, $path, $department, $now, $onProgress, &$totalRows, &$buffer): void {
            DataRecord::query()->where('source_id', $source->id)->delete();

            try {
                $this->streamRows($path, $source, $department, $now, $onProgress, $totalRows, $buffer);
            } catch (Throwable $e) {
                // Consistent, clearly-labeled message regardless of which
                // underlying PhpSpreadsheet/pdfparser exception caused it
                // (corrupt file, unrecognized format signature, etc.) -
                // callers only need to catch one exception type either way.
                $kind = $this->isPdf($path) ? 'PDF' : 'spreadsheet';

                throw new RuntimeException("Could not parse \"{$path}\" as a {$kind}: {$e->getMessage()}", previous: $e);
            }

            if ($buffer !== []) {
                DataRecord::insert($buffer);
            }
        });

        return $totalRows;
    }

    /**
     * @param  (callable(int $rowsSoFar): void)|null  $onProgress
     * @param  list<array<string, mixed>>  $buffer
     */
    private function streamRows(string $path, Source $source, string $department, Carbon $now, ?callable $onProgress, int &$totalRows, array &$buffer): void
    {
        $this->importerFor($source, $path)->stream(
            $path,
            function (int $sheetIndex, string $sheetName, int $rowIndex, array $values) use (
                $source, $department, $now, $onProgress, &$totalRows, &$buffer
            ): void {
                $buffer[] = [
                    'source_id' => $source->id,
                    'department' => $department,
                    'sheet_index' => $sheetIndex,
                    'sheet_name' => $sheetName,
                    'row_index' => $rowIndex,
                    // Model::insert() is a raw query-builder bulk insert -
                    // it does NOT apply Eloquent casts, so the array must
                    // be encoded manually here. Flags must match
                    // App\Casts\UnicodeJsonCast exactly (unescaped
                    // Unicode) - see that class's docblock for why this
                    // matters beyond cosmetics: a LIKE-based text search
                    // against this column depends on it.
                    'data' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $totalRows++;

                if (count($buffer) >= self::INSERT_CHUNK_SIZE) {
                    DataRecord::insert($buffer);
                    $buffer = [];
                }

                if ($onProgress !== null && $totalRows % self::PROGRESS_EVERY_ROWS === 0) {
                    $onProgress($totalRows);
                }
            },
        );
    }

    private function importerFor(Source $source, string $path): RowStreamer
    {
        if (! $this->isPdf($path)) {
            return new RawRowsImport;
        }

        // ocr=true is an explicit, per-source admin opt-in (see
        // OcrPdfTextImport's docblock for why this isn't automatic) for
        // a PDF whose embedded text layer PdfTextImport's own fix can't
        // recover - a different, deeper problem than the one that fix
        // solves.
        return $source->ocr ? new OcrPdfTextImport : new PdfTextImport;
    }

    private function isPdf(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }

    /**
     * Resolve a source to a local, readable file path - the file's own
     * path if type=file, or a freshly-downloaded temp file if type=url.
     *
     * @throws RuntimeException if the local file is missing or the
     *                          download fails.
     */
    private function resolvePath(Source $source): string
    {
        if ($source->type === 'file') {
            if ($source->file_path === null || ! file_exists($source->file_path)) {
                throw new RuntimeException("File not found: {$source->file_path}");
            }

            return $source->file_path;
        }

        if ($source->url === null) {
            throw new RuntimeException("Source #{$source->id} has type=url but no url configured.");
        }

        $response = Http::timeout(30)->get($source->url);

        if ($response->failed()) {
            throw new RuntimeException("Failed to download {$source->url} (status {$response->status()}).");
        }

        // PhpSpreadsheet's IOFactory picks its reader from the file
        // extension, so the temp file needs to match the source URL's
        // real format rather than always being saved as .xlsx.
        $tempPath = tempnam(sys_get_temp_dir(), 'source_').'.'.$this->urlExtension($source->url);
        file_put_contents($tempPath, $response->body());

        return $tempPath;
    }

    /**
     * Catches, upfront, the failure modes that would otherwise produce a
     * confusing PhpSpreadsheet exception (or worse, a silently-empty
     * result) deep inside the parser: a file that exists but is empty,
     * unreadable, or has an extension nothing knows how to route to a
     * reader at all.
     *
     * @throws RuntimeException
     */
    private function validateFile(string $path): void
    {
        if (! is_readable($path)) {
            throw new RuntimeException("File is not readable (check permissions): {$path}");
        }

        $size = filesize($path);

        if ($size === false || $size === 0) {
            throw new RuntimeException("File is empty (0 bytes): {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            throw new RuntimeException(
                "Unsupported file extension \".{$extension}\" (expected one of: "
                .implode(', ', self::SUPPORTED_EXTENSIONS).'): '.$path
            );
        }

        // PhpSpreadsheet's IReadFilter bounds cell construction BEFORE it
        // happens (see RawRowsImport) - smalot/pdfparser has no equivalent,
        // it must parse a PDF's entire object graph into memory before
        // PdfTextImport can extract so much as one line. This upfront size
        // ceiling is the only real defense against an oversized PDF; admin
        // uploads are already capped smaller than this by SourceController's
        // own validation, so this mainly guards url-type PDF sources, which
        // aren't subject to that upload limit at all.
        if ($extension === 'pdf' && $size > self::MAX_PDF_FILE_SIZE_BYTES) {
            $maxMb = self::MAX_PDF_FILE_SIZE_BYTES / 1024 / 1024;
            $actualMb = round($size / 1024 / 1024, 1);

            throw new RuntimeException(
                "PDF file is too large to sync safely ({$actualMb}MB, max {$maxMb}MB): {$path}"
            );
        }
    }

    private function urlExtension(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true) ? $extension : 'xlsx';
    }

    private function recordSuccess(Source $source): void
    {
        $source->update(['last_synced_at' => now(), 'last_sync_error' => null]);
    }

    private function recordFailure(Source $source, string $message): void
    {
        $source->update(['last_sync_error' => $message]);
    }
}
