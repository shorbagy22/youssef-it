<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataRecord;
use App\Services\Support\RawRowPayload;
use Illuminate\Support\Collection;

/**
 * Builds the prompt for POST /api/defects/area-scrap-count - a
 * deliberately narrow tool for ONE known file/sheet: the "area scrap"
 * source's "Total" table, whose column layout was inspected directly
 * (see AreaScrapDefectCountController) and is fixed at:
 *
 *   0 = Date, 4 = Area, 6 = Description, 7 = Quantity, 8 = Defect type
 *
 * Every OTHER AI service in this app (RawRowsImport, ColumnDetectionService,
 * DefectAnalysisService, DefectQueryService, ...) deliberately avoids
 * fixed-column assumptions, because a spreadsheet's column order isn't
 * guaranteed to be the same across different files. This one is the
 * deliberate exception, not an oversight: for a single, already-known
 * file whose layout doesn't change, assuming the position is simpler
 * and more reliable than re-detecting it every time - the trade-off
 * being that this prompt is only ever correct for that ONE file. See
 * AreaScrapDefectCountController for how that scope is enforced in
 * code, not just asked for nicely in the prompt text.
 *
 * Unlike the previous revision of this prompt, this one has no
 * {{question}}/{{filtered_rows}} template markers - buildPrompt()
 * appends DATA then QUESTION after the fixed rules instead, the same
 * convention DefectAnalysisService/DataAnalysisService use for their
 * own non-template prompts.
 *
 * The "ABSOLUTELY FORBIDDEN" list in the prompt text names specific bad
 * outputs (e.g. "the data is from June only", "date not found" without
 * actually scanning) - these read as real behavior seen in practice
 * from the previous revision, not hypothetical failure modes, which is
 * why this version is far more insistent about scanning ALL rows and
 * never assuming a date is out of range.
 */
final class AreaScrapDefectCountService
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are analyzing raw Excel data where each row is an array of values.

        IMPORTANT: The dataset has NO headers, so you MUST use this fixed column mapping:

        - Column 0 = Date
        - Column 4 = Area (e.g., Assembly)
        - Column 6 = Description
        - Column 7 = Quantity
        - Column 8 = Defect type

        STRICT RULES (VERY IMPORTANT):

        1. DO NOT guess or assume missing data.
        2. DO NOT say "data not available" unless you actually checked ALL rows provided.
        3. DO NOT assume months (June, July, etc.) — read the date EXACTLY as written.
        4. DO NOT interpret outside the dataset.
        5. ONLY use rows that are explicitly present.
        6. If a matching row exists → you MUST answer from it.
        7. NEVER say "future date" — treat all dates as valid dataset entries.

        TASK LOGIC:

        When user asks a question like:
        "what's the defect on 21/7/26 in assembly?"

        You MUST:

        1. Scan ALL rows.
        2. Find rows where:
           - Column 0 contains "21/07/26" (or similar format)
           - Column 4 contains "Assembly"
        3. Extract:
           - Column 8 (Defect type)
           - Column 6 (Description, if useful)
           - Column 7 (Quantity, if useful)

        OUTPUT RULES:

        - If matching rows exist:
          Return the defects clearly:

          Example:
          Defects on 21/07/26 in Assembly:
          - كسر (Break) → 3 cases
          - قطع (Cut) → 2 cases
          - فلاووط → 1 case

        - If no matching rows:
          Return ONLY:
          "No defects found for this date"

        ABSOLUTELY FORBIDDEN:
        - Saying "data is from June only"
        - Saying "date not found" without scanning
        - Guessing based on partial rows
        PROMPT;

    /**
     * @param  Collection<int, DataRecord>  $records  Already-filtered
     *         rows from the "area scrap" source's "Total" sheet only -
     *         see class docblock.
     * @param  bool  $confirmedMatch  True when AreaScrapDefectCountController's
     *         own SQL search already found rows matching this exact
     *         question - see DefectQueryService::buildPrompt()'s
     *         matching parameter for why this exists: it turns "does
     *         matching data exist" from a judgment call into a stated
     *         fact, rather than trying to prevent the wrong judgment via
     *         prompt wording alone.
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
