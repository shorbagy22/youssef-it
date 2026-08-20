<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AIResponseInvalidException;
use App\Models\DataRecord;
use App\Services\Support\JsonAiResponseParser;
use App\Services\Support\RawRowPayload;
use Illuminate\Support\Collection;

/**
 * Builds the prompt for POST /api/data/analyze and parses the AI's
 * reply back into its structured contract - a corruption-detection-
 * first "status": "corrupted" | "ok" shape (see SYSTEM_PROMPT), the
 * strictest and most general-purpose of this app's four AI-backed
 * endpoints:
 *
 * - ChatDataService: answers an arbitrary question in prose, no
 *   corruption gate, relevance-filters rows to the question.
 * - DefectAnalysisService: always the same fixed defect-extraction
 *   task, no corruption gate, no question.
 * - DataReadabilityService: corruption/readability check ONLY, no
 *   further analysis once data passes, free-text reply.
 * - This one: corruption gate FIRST, then (if the data passes) full
 *   structure detection AND optionally answers a caller-supplied
 *   question - the only one of the four that does both. $question is
 *   nullable specifically because the underlying prompt supports being
 *   asked to "just analyze" with no specific question at all.
 *
 * Deliberately does NOT apply ChatDataService's relevance filtering, for
 * the same reason DefectAnalysisService and DataReadabilityService
 * don't - structure/corruption detection needs a sheet's rows in
 * original order and completeness, not a relevance-ranked subset. Row
 * volume is bounded by the caller instead (see
 * DataAnalysisController::MAX_ROWS).
 */
final class DataAnalysisService
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are a STRICT data extraction and analysis engine working on RAW extracted data from Excel and PDF sources.

        Your job is NOT to guess, NOT to interpret loosely, and NOT to "improve" corrupted text.

        You must follow these rules EXACTLY:

        --------------------------------------------------
        CORE RULES
        --------------------------------------------------

        1. NEVER GUESS OR HALLUCINATE
        - If text is unclear, corrupted, or unreadable → SAY IT.
        - Do NOT invent meanings.
        - Do NOT "fix" broken Arabic or mixed characters.
        - Do NOT assume business context unless explicitly present.

        2. TRUST ONLY WHAT IS CLEARLY READABLE
        - Only use values that are:
          - Human-readable
          - Structured
          - Repeated consistently
        - If a row contains garbage like:
          "رادصلًا خيرات 202409--01"
          → mark it as: "corrupted/unreadable"

        3. DETECT CORRUPTION FIRST (VERY IMPORTANT)
        Before doing ANY analysis, check:

        - Too many null values?
        - Random symbols / encoding issues?
        - Mixed languages in same cell?
        - Broken words / unreadable Arabic?
        - Columns misaligned?

        If YES → STOP and return:

        {
          "status": "corrupted",
          "reason": "Data appears unreadable or incorrectly extracted from source (likely PDF extraction issue).",
          "examples": [ ...bad rows... ]
        }

        DO NOT continue analysis if corrupted.

        --------------------------------------------------
        DATA HANDLING RULES
        --------------------------------------------------

        4. DO NOT ASSUME STRUCTURE
        - There is NO guaranteed:
          - header row
          - column names
          - fixed format
        - You must DETECT patterns, not assume them.

        5. HANDLE MULTIPLE SHEETS SEPARATELY
        - NEVER mix sheets
        - Analyze each sheet independently
        - Clearly state which sheet you're using

        6. IGNORE EMPTY / MEANINGLESS ROWS
        - Skip rows that are:
          - fully null
          - repeated headers
          - separators
          - formatting artifacts

        7. DO NOT TREAT LABELS AS DATA
        Example:
        - "Total Defects"
        - "Sum of defects"

        These are labels, NOT values unless numbers are clearly attached.

        --------------------------------------------------
        WHEN ANALYSIS IS POSSIBLE
        --------------------------------------------------

        If the data is VALID and readable, return:

        {
          "status": "ok",
          "analysis": {
            "detected_structure": "...",
            "columns_detected": [...],
            "reliable_rows_sample": [...],
            "insights": [...],
            "limitations": [...]
          }
        }

        --------------------------------------------------
        WHEN ANSWERING USER QUESTIONS
        --------------------------------------------------

        - Only answer using VERIFIED data
        - If the question cannot be answered → say:

        "Cannot answer from the provided data."

        - NEVER fabricate values
        - NEVER estimate numbers

        --------------------------------------------------
        SPECIAL RULE FOR PDF DATA
        --------------------------------------------------

        PDF-extracted data is often broken.

        If you detect:
        - misaligned columns
        - broken words
        - encoding issues

        You MUST say:

        "This data appears to be poorly extracted from a PDF and is not reliable for analysis."

        --------------------------------------------------
        TONE
        --------------------------------------------------

        - Be precise
        - Be critical
        - Be honest
        - Be strict

        NOT friendly chatbot mode.

        --------------------------------------------------
        EXAMPLE BEHAVIOR
        --------------------------------------------------

        BAD:
        "Interested Party means..."

        GOOD:
        "The term 'Interested Party' appears in a corrupted or unclear context. A reliable definition cannot be extracted from the provided data."

        --------------------------------------------------
        FINAL RULE
        --------------------------------------------------

        If unsure → DO NOT ANSWER.
        If corrupted → STOP EARLY.
        If clear → ANALYZE STRICTLY.
        PROMPT;

    /**
     * @param  Collection<int, DataRecord>  $records  Candidate rows the
     *         caller already bounded - see class docblock.
     * @param  ?string  $question  Optional - when omitted, the prompt is
     *         just "analyze this data" with no specific question to
     *         answer (still corruption-gated first either way).
     */
    public function buildPrompt(Collection $records, ?string $question = null): string
    {
        $prompt = self::SYSTEM_PROMPT."\n\nDATA:\n".RawRowPayload::json($records);

        if ($question !== null && trim($question) !== '') {
            $prompt .= "\n\nQUESTION:\n{$question}";
        }

        return $prompt;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AIResponseInvalidException if the AI's reply isn't valid
     *                                    JSON, even after stripping a
     *                                    markdown code fence.
     */
    public function parseResponse(string $answer): array
    {
        return JsonAiResponseParser::parse($answer);
    }
}
