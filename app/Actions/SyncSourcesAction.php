<?php

declare(strict_types=1);

namespace App\Actions;

use App\Imports\RawRowsImport;
use App\Models\DataRecord;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Syncs one Source's Excel file into DataRecord rows: resolves the file
 * (local path, or downloads a url-type source to a temp file), parses it
 * with maatwebsite/excel, and upserts one DataRecord per (department,
 * date) pair. Called once per source by the sources:sync command, which
 * is responsible for catching failures and moving on to the next source
 * - a single source's bad file never aborts the whole sync run.
 *
 * Excel column layout is fixed and positional: column 0 = date, column 1
 * = nrft, column 2 = ppm, column 3 = defects (comma-separated). Row 0 is
 * always assumed to be a header row and is skipped.
 */
final class SyncSourcesAction
{
    /**
     * Sync one source and return how many records were written.
     *
     * @throws RuntimeException if the file can't be resolved or read.
     */
    public function sync(Source $source): int
    {
        $path = $this->resolvePath($source);
        $isTemporary = $source->type === 'url';

        try {
            $import = new RawRowsImport;
            Excel::import($import, $path);
            $rows = $import->rows();

            $count = 0;

            foreach ($rows->skip(1) as $row) {
                if (blank($row[0] ?? null)) {
                    continue;
                }

                DataRecord::query()->updateOrCreate(
                    [
                        'department' => $source->department->slug,
                        'date' => $this->parseDate($row[0]),
                    ],
                    [
                        'nrft' => $this->parseNumber($row[1] ?? null),
                        'ppm' => $this->parseNumber($row[2] ?? null),
                        'defects' => $this->parseDefects($row[3] ?? null),
                        'extra_data' => [],
                    ],
                );

                $count++;
            }

            $source->update(['last_synced_at' => now()]);

            return $count;
        } finally {
            if ($isTemporary && file_exists($path)) {
                @unlink($path);
            }
        }
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

        $tempPath = tempnam(sys_get_temp_dir(), 'source_').'.xlsx';
        file_put_contents($tempPath, $response->body());

        return $tempPath;
    }

    private function parseDate(mixed $value): Carbon
    {
        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
        }

        return Carbon::parse((string) $value);
    }

    private function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function parseDefects(mixed $value): array
    {
        if (blank($value)) {
            return [];
        }

        return collect(explode(',', (string) $value))
            ->map(fn (string $defect): string => trim($defect))
            ->filter()
            ->values()
            ->all();
    }
}
