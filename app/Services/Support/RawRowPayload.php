<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\DataRecord;
use Illuminate\Support\Collection;

/**
 * The {sheet_name, row_index, values} shape shared by every AI prompt
 * that sends a flat list of raw DataRecord rows (not grouped per-source
 * the way ChatDataService's Q&A payload is) - used by both
 * DefectAnalysisService and DataReadabilityService, which both hand the
 * AI a caller-bounded set of rows to look at directly, with no
 * relevance filtering or source grouping applied first.
 */
final class RawRowPayload
{
    /**
     * @param  Collection<int, DataRecord>  $records
     * @return list<array{sheet_name: string, row_index: int, values: mixed}>
     */
    public static function rows(Collection $records): array
    {
        return $records
            ->map(fn (DataRecord $record): array => [
                'sheet_name' => $record->sheet_name,
                'row_index' => $record->row_index,
                'values' => $record->data,
            ])
            ->values()
            ->all();
    }

    /**
     * JSON_UNESCAPED_UNICODE is not cosmetic here - without it, PHP's
     * json_encode() escapes every non-ASCII character (all of this
     * app's Arabic content) to "\uXXXX" sequences. That's still
     * technically valid JSON an LLM can parse, but a real, confirmed
     * problem for what these prompts ask the AI to DO with that text:
     * group rows by an Arabic defect name, or return an EXACT sentence
     * verbatim (PdfQaService) - both need the model looking at the
     * actual characters, not decoding escapes first. Found via a
     * failing test that asserted a known Arabic defect word literally
     * appeared in a built prompt and didn't, tracing back to this
     * missing flag.
     *
     * @param  Collection<int, DataRecord>  $records
     */
    public static function json(Collection $records, string $rootKey = 'rows'): string
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $json = json_encode([$rootKey => self::rows($records)], $flags);

        return $json !== false ? $json : json_encode([$rootKey => []], $flags);
    }
}
