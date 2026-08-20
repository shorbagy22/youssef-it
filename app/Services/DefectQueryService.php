<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataRecord;
use App\Services\Support\RawRowPayload;
use Illuminate\Support\Collection;

/**
 * Builds the prompt for POST /api/defects/query - a targeted "what
 * defects happened on this date/in this area" lookup, distinct from
 * every other AI service in this app:
 *
 * - DefectAnalysisService: always the same fixed task (summarize every
 *   defect type across the whole dataset), no question, no date/area
 *   filtering, JSON output.
 * - AreaScrapDefectCountService: the deliberate FIXED-column exception,
 *   scoped to one specific known file/sheet - see that class's docblock.
 * - ChatDataService: free-form Q&A, any topic, prose output.
 * - This one: dynamic column detection (never assumes position - the
 *   dataset's structure isn't guaranteed, per the prompt itself),
 *   answers a SPECIFIC date/area question, and lists the matching
 *   defect names (see SYSTEM_PROMPT) - simpler than
 *   AreaScrapDefectCountService's per-defect counting, and, unlike that
 *   one, meant to work for ANY file's layout, not one known sheet.
 *
 * Deliberately does NOT do its own row-fetching or filtering -
 * DefectQueryController reuses ChatDataService::findRelevantRecords()
 * for that (the same SQL-level date/keyword search proven against this
 * app's real "assembly area daily scrap" data), since "find the rows
 * matching a date/keyword in a question, across the whole department"
 * is already a solved, tested problem - this class only turns whatever
 * rows it's given into a prompt.
 *
 * Unlike the previous revision (which assumed rows always arrive
 * pre-filtered), this prompt explicitly plans for BOTH cases -
 * DefectQueryController's own fallback (see that class) DOES sometimes
 * hand this a broad, unfiltered natural-order sample when
 * findRelevantRecords() found no date/keyword signal at all, so a
 * prompt that only knew how to trust an already-narrow row set was
 * describing an assumption the controller doesn't actually guarantee.
 * This version tells the AI to detect which situation it's in (STEP 2)
 * and filter accordingly, rather than always skipping filtering.
 *
 * No {{...}} template markers - buildPrompt() appends DATA then
 * QUESTION after the fixed rules, the same convention
 * AreaScrapDefectCountService and DefectAnalysisService/
 * DataAnalysisService use.
 */
final class DefectQueryService
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are part of a data pipeline with TWO layers:

        1) PHP backend → filters and prepares Excel rows
        2) You (AI) → extract the final answer

        You MUST respect this architecture.

        -------------------------------------
        SYSTEM UNDERSTANDING (CRITICAL)
        -------------------------------------

        - The backend MAY send:
          A) FULL dataset
          B) FILTERED subset of rows

        You MUST handle BOTH correctly.

        -------------------------------------
        STEP 1 — UNDERSTAND DATA STRUCTURE
        -------------------------------------

        Each row is an array of values.

        Example:
        ["21/07/26", "1126312302", "7", "943006580", "Assembly", "A03717604", "...", "15", "كسر", "اثناء التجميع"]

        You must dynamically detect columns:

        - DATE column → contains date-like values (21/7/26, 20/07/26, etc.)
        - AREA column → contains words like "Assembly", "Production"
        - DEFECT column → contains descriptive text (Arabic or English)
        - QUANTITY column → numeric values (optional)

        DO NOT assume fixed column index.

        -------------------------------------
        STEP 2 — DETECT IF DATA IS FILTERED
        -------------------------------------

        If MOST rows already match the user question:
        → Data is FILTERED → DO NOT re-filter heavily

        If rows contain mixed dates/areas:
        → Data is FULL → you MUST filter

        -------------------------------------
        STEP 3 — FILTER LOGIC
        -------------------------------------

        For question like:
        "defect on 21/7/26 in assembly"

        You MUST:

        1. Find rows where:
           - Date matches 21/7/26 (accept similar formats)
           - Area contains "Assembly" (case-insensitive)

        2. DO NOT:
           - Ignore valid rows
           - Assume missing months
           - Restrict to part of dataset

        -------------------------------------
        STEP 4 — EXTRACTION (STRICT)
        -------------------------------------

        From matched rows, extract:

        - Defect names (REQUIRED)
        - Quantities (if present)

        -------------------------------------
        STEP 5 — VALIDATION (VERY IMPORTANT)
        -------------------------------------

        Before answering:

        - If at least ONE matching row exists → YOU MUST return results
        - You are NOT allowed to say:
          - "data not found"
          - "dataset does not contain"
          IF matching rows exist

        -------------------------------------
        STEP 6 — OUTPUT FORMAT
        -------------------------------------

        If results found:

        Defects on <date> in Assembly:

        - <defect 1>
        - <defect 2>

        If quantities exist:

        - <defect> → <quantity>

        If no rows match:

        "No defects found for the specified date and area."

        -------------------------------------
        STRICT RULES (DO NOT BREAK)
        -------------------------------------

        - DO NOT hallucinate
        - DO NOT assume missing data
        - DO NOT explain dataset limitations
        - DO NOT mention months like June/May unless explicitly in rows
        - DO NOT ignore rows containing correct date
        - ALWAYS scan ALL provided rows

        -------------------------------------
        PERFORMANCE AWARENESS
        -------------------------------------

        - If many rows are provided, process efficiently
        - If rows are already filtered, answer directly
        - DO NOT over-analyze

        -------------------------------------
        FINAL RULE
        -------------------------------------

        If the correct date EXISTS in ANY row,
        you MUST return the defects from that row.
        PROMPT;

    /**
     * @param  Collection<int, DataRecord>  $records  Already-filtered
     *         candidate rows - see class docblock for why this doesn't
     *         do its own row-fetching.
     * @param  bool  $confirmedMatch  True when DefectQueryController's own
     *         SQL search (ChatDataService::findRelevantRecords()) already
     *         found rows matching this exact question - see that
     *         method's hasSearchableSignal() docblock for why this
     *         matters: it turns "does matching data exist" from a
     *         judgment call the AI has to make (and can get wrong no
     *         matter how the prompt is worded) into a fact it's simply
     *         told, removing the failure mode entirely rather than
     *         asking the prompt to prevent it more forcefully.
     */
    public function buildPrompt(Collection $records, string $question, bool $confirmedMatch = false): string
    {
        $prompt = self::SYSTEM_PROMPT."\n\nDATA:\n".RawRowPayload::json($records);

        if ($confirmedMatch) {
            $prompt .= "\n\nBACKEND CONFIRMATION:\n"
                .'The backend already searched the full dataset and confirmed '
                .$records->count().' row(s) matching this exact question. '
                .'This is a FACT, not something to verify - do not say the data '
                .'is missing or the date was not found. Extract and report the '
                .'defects from the rows below.';
        }

        return $prompt."\n\nQUESTION:\n{$question}";
    }
}
