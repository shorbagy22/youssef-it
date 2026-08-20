<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AIResponseInvalidException;
use App\Models\DataRecord;
use App\Services\Support\JsonAiResponseParser;
use App\Services\Support\RawRowPayload;
use Illuminate\Support\Collection;

/**
 * Builds the prompt for POST /api/defects/analyze and parses the AI's
 * reply back into the structured schema that endpoint promises its
 * callers (detected_tables/defects/... or the no-data error shape - see
 * SYSTEM_PROMPT below for the exact contract). A dedicated prompt and
 * parser from ChatDataService's free-text Q&A one: that one answers an
 * arbitrary question in prose; this one always asks the same fixed
 * question ("find and summarize defects in this data") and always
 * expects JSON back, so it needs its own system prompt and its own
 * response parsing, not a shared one.
 *
 * Deliberately does NOT apply ChatDataService's date/keyword relevance
 * filtering - that filtering picks rows likely to answer a specific
 * free-text question, which is the wrong tool here: table/header
 * detection needs to see a sheet's rows in their original order and
 * completeness, not a relevance-ranked subset. Row volume is bounded
 * instead by the caller handing this a capped candidate pool (see
 * DefectAnalysisController::CANDIDATE_POOL_LIMIT).
 */
final class DefectAnalysisService
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are analyzing raw spreadsheet data extracted from Excel.

        IMPORTANT:
        - The dataset may contain:
          - empty rows
          - header rows
          - summary rows
          - multiple sheets mixed together
        - DO NOT assume structure
        - DO NOT assume first row is header

        Your job:

        1. Ignore completely empty rows
        2. Detect tables inside the data
        3. Identify header rows dynamically
        4. Extract meaningful data rows under those headers
        5. If a header exists without data - IGNORE it

        Specifically for defect analysis:
        - Look for columns like:
          "Defect", "Sum", "%", "PPM"
        - Only treat rows as valid if they contain:
          - a defect name (string)
          - AND at least one numeric value

        Output ONLY valid JSON, no other text, no markdown code fences,
        matching exactly this shape:

        {
          "detected_tables": [...],
          "defects": [
            {
              "name": "...",
              "count": ...,
              "percentage": ...
            }
          ],
          "ignored_rows_reason": "...",
          "confidence": "low | medium | high"
        }

        If no real data rows exist, return exactly this instead:

        {
          "error": "No valid defect data found",
          "reason": "Headers exist but no populated rows"
        }
        PROMPT;

    /**
     * @param  Collection<int, DataRecord>  $records  Candidate rows the
     *         caller already bounded - see class docblock for why this
     *         doesn't do its own relevance filtering the way
     *         ChatDataService does.
     */
    public function buildPrompt(Collection $records): string
    {
        return self::SYSTEM_PROMPT."\n\nDATA:\n".RawRowPayload::json($records);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AIResponseInvalidException if the AI's reply isn't valid
     *                                    JSON, even after stripping a
     *                                    markdown code fence - the one
     *                                    thing LLMs reliably do wrong
     *                                    despite being told not to.
     */
    public function parseResponse(string $answer): array
    {
        return JsonAiResponseParser::parse($answer);
    }
}
