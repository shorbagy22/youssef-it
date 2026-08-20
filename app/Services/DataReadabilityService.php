<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataRecord;
use App\Services\Support\RawRowPayload;
use Illuminate\Support\Collection;

/**
 * Builds the prompt for POST /api/data/check-readability - a strict
 * "can anything reliable even be read from this data" gate, distinct
 * from both ChatDataService (answers an arbitrary question in prose)
 * and DefectAnalysisService (always returns a fixed JSON defect
 * schema). This one has no output contract to enforce: the system
 * prompt itself specifies the exact strings to return for the corrupt/
 * no-structure cases, and otherwise expects free-text column/value
 * extraction - so, unlike DefectAnalysisService, there's no
 * parseResponse() here; the AI's reply is returned to the caller as-is,
 * the same way ChatDataService's is.
 *
 * Most useful pointed at ONE just-synced source (via
 * DataReadabilityController's optional source_id) right after adding a
 * new PDF - PDF text extraction is this app's most likely source of
 * genuinely garbled output (bad font encoding, embedded-font ligature
 * corruption), unlike Excel's raw cell values, which are essentially
 * never "unreadable" in this sense even when meaningless.
 *
 * Deliberately does NOT apply ChatDataService's relevance filtering, for
 * the same reason DefectAnalysisService doesn't - see that class's
 * docblock. Row volume is bounded by the caller instead (see
 * DataReadabilityController::MAX_ROWS).
 */
final class DataReadabilityService
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are analyzing raw extracted data from Excel or PDF.

        CRITICAL RULES:
        - Do NOT guess or invent meaning.
        - Do NOT assume structure if it is unclear.
        - If text appears corrupted, unreadable, or encoded incorrectly, say:
          "The data appears corrupted or unreadable. Cannot extract reliable meaning."
        - If headers are missing, say:
          "No clear structure detected."

        WHAT TO DO:
        1. Detect if the data is readable or corrupted.
        2. If readable:
           - Identify columns if possible
           - Extract actual values only
        3. If NOT readable:
           - STOP and report the issue

        NEVER:
        - invent categories
        - invent labels like "references" or "procedures"
        - assume page numbers or meanings

        Be strict and honest.
        PROMPT;

    /**
     * @param  Collection<int, DataRecord>  $records  Candidate rows the
     *         caller already bounded - see class docblock.
     */
    public function buildPrompt(Collection $records): string
    {
        return self::SYSTEM_PROMPT."\n\nDATA:\n".RawRowPayload::json($records);
    }
}
