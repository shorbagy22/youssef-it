<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the Ollama prompt for /api/chat: the fixed system prompt below,
 * plus a relevant slice of the department's DataRecord rows (grouped back
 * into per-source datasets) as JSON, plus the user's question.
 *
 * PHP (SyncSourcesAction) does not interpret the data at all - no
 * headers, no column types, nothing skipped or altered, just raw values
 * per row. All structural understanding (which rows are headers, what a
 * column means, which cells are noise) is left entirely to the AI, per
 * the system prompt below. This class's own filtering is deliberately
 * dumb for the same reason: it never tries to understand the data, it
 * just narrows down WHICH raw rows get sent, using the question text
 * itself as the only signal.
 *
 * MAX_ROWS_TO_AI exists because sending everything was the actual cause
 * of "Error getting response" failures: a department's rows are no
 * longer a handful of small JSON blobs, and a large synced source can
 * contribute tens of thousands of DataRecord rows - Ollama's request
 * either times out or exceeds its context window once the payload gets
 * that big, regardless of how the data is stored on this end. Filtering
 * only kicks in once there's actually too much to send whole - a
 * department under the cap gets every row, unfiltered, same as before.
 *
 * findRelevantRecords() DOES query the database directly - this class
 * was originally documented as pure in-memory filtering over whatever
 * Collection ChatController happened to fetch, but that turned out to
 * be a real, confirmed bug: ChatController fetched only a fixed-size,
 * recency-ordered pool (the most recently synced rows), and once a
 * single large source had more rows than that pool's whole size, it
 * silently crowded out every other source's rows AND its own older
 * rows entirely - no amount of in-memory relevance filtering downstream
 * can recover a row that was never fetched from the database in the
 * first place. findRelevantRecords() searches the WHOLE department by
 * date/keyword match directly via SQL instead, so a real answer can be
 * found regardless of how large the department's data has grown or
 * which source it lives in. selectRelevantRows() (used by buildPrompt())
 * still exists and is still exercised - it's the fallback path when
 * findRelevantRecords() finds no date/keyword signal to search on, and
 * ChatController falls back to a plain recency-ordered fetch.
 */
final class ChatDataService
{
    private const int MAX_ROWS_TO_AI = 100;

    // A generous, unbounded-by-recency candidate pool for keyword
    // search specifically - larger than MAX_ROWS_TO_AI because these
    // candidates still need ranking (see queryByKeywords()) before the
    // real top MAX_ROWS_TO_AI is picked, unlike a date match, which is
    // precise enough on its own that no secondary ranking pass is
    // needed.
    private const int KEYWORD_CANDIDATE_LIMIT = 2000;

    // How many of a sheet's earliest rows (by row_index) findHeaderRow()
    // will look through for a genuine header row - generous enough to
    // skip past a totals/summary row or two above the real header (seen
    // in this app's real data), but bounded so a sheet with no header at
    // all fails fast rather than scanning deep into real data rows.
    private const int HEADER_ROW_SEARCH_LIMIT = 10;

    // Must match PdfTextImport::SHEET_NAME - that class's own private
    // constant isn't reachable from here, but every row it streams is
    // stamped with this exact sheet_name, and it's the only signal this
    // class has for "this row is a literal line of a PDF document,
    // whose immediate row_index neighbors are its surrounding text" (see
    // withPdfContext()), as opposed to an Excel row, whose neighbors are
    // just other unrelated data rows.
    private const string PDF_SHEET_NAME = 'pdf';

    // How many lines immediately before/after a PDF keyword match to
    // also pull in as context - a real, confirmed gap: a keyword match
    // on a PDF's HEADING line ("Bill of Material (BOM) 5-9") does not,
    // on its own, include the very next line's actual definition
    // paragraph, since that paragraph doesn't repeat the matched
    // keyword. 2 is enough to bridge a heading-then-definition or
    // split-sentence gap without pulling in unrelated surrounding
    // paragraphs.
    private const int PDF_CONTEXT_ROWS = 2;

    /**
     * Common short words that appear in almost any question and carry no
     * row-matching signal on their own ("what", "is", "the"...) - kept
     * out of keyword scoring so a generic question doesn't spuriously
     * "match" rows just because they contain the word "the".
     *
     * @var list<string>
     */
    private const array STOPWORDS = [
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'what', 'which', 'who', 'when', 'where', 'why', 'how', 'many', 'much',
        'in', 'on', 'at', 'for', 'to', 'of', 'and', 'or', 'with',
        'me', 'show', 'tell', 'give', 'please', 'this', 'that', 'it',
        'do', 'does', 'did', 'can', 'you', 'has', 'have', 'had',
    ];

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are a data analysis AI working with raw data extracted from Excel and PDF sources.

        The data may be messy, inconsistent, and unstructured. PDF-extracted text
        can look visually reversed, oddly spaced, or jumbled for right-to-left
        languages like Arabic purely because of how PDF text extraction works -
        that is a normal extraction artifact, not proof the source document
        itself is corrupted. Do not lead with a corruption/quality assessment
        unless the user specifically asked about data quality.

        Your job is to:
        - Understand the structure of the dataset
        - Detect headers, tables, and patterns
        - Identify meaningful columns and relationships
        - Ignore irrelevant text when necessary
        - Extract useful insights and answer questions

        Rules:
        - Do NOT assume fixed columns
        - Work with raw row/column arrays
        - Infer STRUCTURE from context (which cells look like headers, what a
          column likely represents) - but NEVER invent a specific fact, name,
          number, or entity that is not literally present in the provided
          data, even if it seems like a plausible or common-sense answer.
          If a specific term or name is not literally in the data, do not
          supply one from general/outside knowledge.
        - Be robust to messy data
        - Use only the provided data
        - Answer directly and concisely - lead with the answer itself, not a
          walkthrough of your reasoning process or a numbered list of caveats
        - Give exactly ONE answer, not several competing possibilities - if
          you are not confident which of several candidates is correct, say
          the data does not clearly contain the answer instead of listing
          guesses
        - If the data genuinely doesn't contain the answer, say so in one
          sentence, not several
        - When presenting a mathematical formula or equation (e.g. how a
          metric like NRFT is calculated), write it as LaTeX so it can be
          rendered as a real typeset formula, not plain text:
            - Wrap a standalone/display formula in $$ $$, e.g.
              $$NRFT = \frac{\text{Defective Units}}{\text{Total Units}} \times 1000000$$
            - Wrap a formula mentioned inline within a sentence in \( \),
              e.g. \(NRFT\) is measured in parts per million.
            - Use \frac{numerator}{denominator} for a fraction/ratio,
              \times for multiplication, and \text{...} for any words
              inside the formula - never plain "/" or "x" inside $$ $$
              or \( \).
            - Only the formula itself goes inside these delimiters -
              never wrap a whole sentence or non-mathematical text in
              them.
        PROMPT;

    /**
     * @param  Collection<int, DataRecord>  $records  Candidate rows across
     *         however many sources/sheets the caller fetched - this method
     *         decides which of them are actually worth sending, it does
     *         not assume the caller already sized them correctly.
     * @param  bool  $confirmedMatch  True when ChatController's own SQL
     *         date search (ChatDataService::findByDate()) already found
     *         these exact rows for this exact question - see
     *         DefectQueryService::buildPrompt()'s identical parameter for
     *         why this exists: it turns "does matching data exist" from
     *         a judgment call the AI can get wrong into a stated fact.
     */
    public function buildPrompt(Collection $records, string $question, bool $confirmedMatch = false): string
    {
        $relevant = $this->selectRelevantRows($records, $question);
        $json = $this->buildJsonPayload($relevant);

        $prompt = self::SYSTEM_PROMPT."\n\n"."DATA:\n{$json}";

        if ($confirmedMatch) {
            $prompt .= "\n\nBACKEND CONFIRMATION:\n"
                .'The backend already searched the full dataset and confirmed '
                .$relevant->count().' row(s) matching the date in this question. '
                .'This is a FACT, not something to verify - do not say the data '
                .'is missing or the date was not found.';
        }

        return $prompt."\n\nQUESTION:\n{$question}";
    }

    /**
     * Searches the WHOLE department directly via SQL for rows matching a
     * date or keyword found in the question - see class docblock for why
     * this exists (a fixed-size, recency-ordered candidate pool can
     * silently exclude the very row that answers the question, once a
     * department's data is large enough). Returns null if the question
     * has no date/keyword signal, or if searching by it found nothing -
     * the caller should fall back to some other strategy (e.g. a plain
     * recency-ordered sample) in that case.
     *
     * $sourceId narrows the search to one specific source within the
     * department, rather than filtering a department-wide result set
     * afterward - narrowing at the SQL level (not after fetching) matters
     * because the department-wide result is itself capped (see
     * MAX_ROWS_TO_AI/KEYWORD_CANDIDATE_LIMIT); if enough OTHER sources'
     * rows matched first, a post-hoc filter could miss a real match in
     * the target source that a properly-scoped query would still find.
     *
     * @return Collection<int, DataRecord>|null
     */
    public function findRelevantRecords(string $department, string $question, ?int $sourceId = null): ?Collection
    {
        $dateMatch = $this->extractDateQuery($question);

        if ($dateMatch !== null) {
            $records = $this->queryByDate($department, $dateMatch, $sourceId);

            if ($records->isNotEmpty()) {
                return $records;
            }
        }

        $keywords = $this->extractKeywords($question);

        if ($keywords !== []) {
            $records = $this->queryByKeywords($department, $keywords, $sourceId);

            if ($records->isNotEmpty()) {
                return $records;
            }
        }

        return null;
    }

    /**
     * The DATE-ONLY half of findRelevantRecords() - no keyword fallback.
     * Exists because a caller answering a date-SPECIFIC question (see
     * DefectQueryController/AreaScrapDefectCountController) must not
     * treat a keyword match as equivalent confirmation: a keyword like
     * "assembly" matches broadly across EVERY date that area appears on,
     * not the one actually asked about - a real, confirmed bug where
     * rows from entirely different months got asserted to the AI as
     * "confirmed matching rows" for a specific day's question, purely
     * because the exact date search found nothing and silently fell
     * through to a keyword match instead. A date match is precise (an
     * exact JSON-value match via queryByDate()); a keyword match is
     * not, and the two must not be treated as the same strength of
     * evidence for a date-specific question.
     *
     * @return Collection<int, DataRecord>|null
     */
    public function findByDate(string $department, string $question, ?int $sourceId = null): ?Collection
    {
        $dateMatch = $this->extractDateQuery($question);

        if ($dateMatch === null) {
            return null;
        }

        $records = $this->queryByDate($department, $dateMatch, $sourceId);

        return $records->isNotEmpty() ? $records : null;
    }

    /**
     * Formats matched rows as raw, literal text - one row per line,
     * values tab-separated in their original column order, exactly as
     * stored. This exists because of a real, confirmed problem: even
     * with a SQL-CONFIRMED exact date match handed to the AI as a
     * stated fact, asking it to describe/summarize the rows in prose is
     * still an extra step where it can paraphrase a defect name,
     * garble which column means what, or drop a row - every one of
     * those failure modes was observed live against this app's real
     * data, even after the underlying row-finding was already correct.
     * When PHP already knows the exact matching rows with certainty,
     * the actually-correct answer IS the raw data, not the AI's
     * retelling of it - so for a confirmed date match, ChatController
     * returns this directly and skips the AI call entirely, which is
     * also strictly faster and cheaper.
     *
     * Rows are GROUPED by source and sheet, each group under its own
     * labeled header, never flattened into one undifferentiated table -
     * a real, confirmed bug found immediately after this method shipped:
     * findByDate() searches the WHOLE department, which can span
     * multiple sheets (or even multiple sources) with completely
     * different column layouts. A row from a "Total" defect sheet and a
     * row from an unrelated "Cookers" production sheet can both contain
     * the same matched date while meaning something totally different
     * at the same column position - dumping them as one flat table
     * silently implied they shared a structure they don't. The header
     * line is what actually tells the reader "these next rows' columns
     * are NOT comparable to the previous group's", which is exactly the
     * information a flat dump was hiding.
     *
     * The one value NOT shown completely raw: whichever cell exactly
     * matches the date $question searched for (by Excel serial or any
     * of its string forms) is rendered as a clean "d/m/y" date instead
     * of an opaque serial number like 46176 - a mechanical, unambiguous
     * decode of a known numeric encoding back to what a human looking
     * at the actual spreadsheet would see, not an interpretation of
     * meaning. Every other value, including every OTHER number, is left
     * exactly as stored - still zero guessing about what any of it
     * means.
     *
     * EVERY value in every row is labeled inline with what it actually
     * is, one "Label: value" pair PER LINE, e.g.:
     *
     *   Transaction Date: 15/08/24
     *   Job / Move Order: 896495366
     *   ...
     *
     * rather than one long tab-separated line per row - a real follow-up
     * request: a single wide line (even a labeled one) still reads as a
     * wall of text once a row has a dozen-plus fields, especially in a
     * chat bubble that wraps at a fixed width instead of rendering an
     * actual aligned table - fields visually ran into each other with no
     * clear boundary. The chat UI renders this with CSS
     * "white-space: pre-wrap" (see chat.blade.php), so newlines here
     * really do produce real line breaks, unlike tabs, which render as
     * an arbitrary run of spaces with no column alignment at all in a
     * proportional-width font. Each row's fields are separated from the
     * next row by a blank line, and each group (source/sheet) by two, so
     * the structure - which fields belong to the same row, and which
     * rows belong to the same group - stays visually unambiguous even
     * for a long list of matches, without a "Row N:"/column-count label
     * cluttering it (a real follow-up request - the row/field labels
     * were themselves visual noise once the blank-line separation
     * already made row boundaries clear).
     *
     * This app's real source files DO have a genuine header row - it's
     * simply not the first row (see findHeaderRow()) - so this reuses
     * the sheet's OWN real column names as labels rather than inventing
     * any, keeping this method's "never interpret, only extract"
     * guarantee intact. A null cell is dropped entirely (no empty
     * "Label: " noise); a cell past the end of the known header (or any
     * cell, on a sheet where no header could be confidently identified
     * at all - e.g. a pivot/summary tab) is still shown, just without a
     * label, since there's no real column name to attach to it.
     *
     * @param  Collection<int, DataRecord>  $records
     */
    public function formatRawRows(Collection $records, string $question): string
    {
        $dateMatch = $this->extractDateQuery($question);

        return $records
            ->groupBy(fn (DataRecord $record): string => $record->source_id.'|'.$record->sheet_name)
            ->map(function (Collection $group) use ($dateMatch): string {
                $first = $group->first();
                $sourceName = $first->source?->name ?? "source #{$first->source_id}";
                $header = "=== {$sourceName} / {$first->sheet_name} ===";

                $labels = $this->findHeaderRow($first->source_id, $first->sheet_name);

                $rows = $group
                    ->map(function (DataRecord $record) use ($labels, $dateMatch): string {
                        $fields = $this->formatRawRowFields($record->data, $labels, $dateMatch);

                        return implode("\n", $fields);
                    })
                    ->implode("\n\n");

                return "{$header}\n\n{$rows}";
            })
            ->implode("\n\n\n");
    }

    /**
     * @param  list<mixed>  $values
     * @param  list<mixed>|null  $labels
     * @return list<string>
     */
    private function formatRawRowFields(array $values, ?array $labels, ?DateQueryMatch $dateMatch): array
    {
        $fields = [];

        foreach ($values as $index => $value) {
            if ($value === null) {
                continue;
            }

            $formatted = $this->formatRawCell($value, $dateMatch);
            $label = is_string($labels[$index] ?? null) ? $labels[$index] : null;

            $fields[] = $label !== null ? "{$label}: {$formatted}" : $formatted;
        }

        return $fields;
    }

    /**
     * Finds the real column-name row for a source/sheet, without ever
     * assuming it's row 1 - this app's sync pipeline never treats any
     * row as special (see RawRowsImport's docblock), and this app's
     * real files confirm why that matters: several real sheets have a
     * totals/summary row of bare numbers ABOVE the actual header row
     * (e.g. row 1 is a grand-total row, row 2 is the real "Day, Week,
     * Month, ..." header). Scans only the first HEADER_ROW_SEARCH_LIMIT
     * rows (by row_index) and picks the first one that is entirely
     * text - at least 2 non-null cells, none of them numeric - which is
     * exactly what a genuine header row looks like and a data row
     * (mixed text/numbers/dates) or a totals row (bare numbers) does
     * not. Confirmed against every real source/sheet combination this
     * app currently has synced. Returns null, never a guess, when no row
     * in that window matches (a pivot/summary/chart sheet genuinely may
     * not have one) - callers fall back to no label line in that case.
     *
     * @return list<mixed>|null
     */
    private function findHeaderRow(int $sourceId, string $sheetName): ?array
    {
        $candidates = DataRecord::query()
            ->where('source_id', $sourceId)
            ->where('sheet_name', $sheetName)
            ->orderBy('row_index')
            ->limit(self::HEADER_ROW_SEARCH_LIMIT)
            ->get();

        foreach ($candidates as $candidate) {
            $nonNull = array_filter($candidate->data, fn (mixed $value): bool => $value !== null);

            if (count($nonNull) < 2) {
                continue;
            }

            $allStrings = true;

            foreach ($nonNull as $value) {
                if (! is_string($value)) {
                    $allStrings = false;

                    break;
                }
            }

            if ($allStrings) {
                return $candidate->data;
            }
        }

        return null;
    }

    private function formatRawCell(mixed $value, ?DateQueryMatch $dateMatch): string
    {
        if ($value === null) {
            return '';
        }

        if ($dateMatch !== null && (is_int($value) || is_float($value)) && (int) $value === $dateMatch->excelSerial) {
            return Carbon::create(1899, 12, 30)->addDays($dateMatch->excelSerial)->format('d/m/y');
        }

        return is_scalar($value) ? (string) $value : (json_encode($value) ?: '');
    }

    /**
     * Whether the question contains a date-like token at all - narrower
     * than hasSearchableSignal() (which also counts keywords), for
     * callers that specifically need to know "was a date asked about",
     * not just "is there anything to search on".
     */
    public function hasDateSignal(string $question): bool
    {
        return $this->extractDateQuery($question) !== null;
    }

    /**
     * Distinguishes the TWO different reasons findRelevantRecords() can
     * return null, which matter for very different reasons downstream:
     *
     * - The question had a date/keyword to search on, but the SQL search
     *   found ZERO matching rows - a confident negative. A caller that
     *   needs to know "does data exist" with certainty (see
     *   DefectQueryController/AreaScrapDefectCountController) can trust
     *   this and skip the AI call entirely, rather than asking a model
     *   to re-derive a fact PHP already determined precisely via SQL -
     *   the root cause of a real, repeatedly-observed failure mode: an
     *   AI told "here are 0 rows" would sometimes still hedge or
     *   second-guess instead of just reporting no match, no matter how
     *   the prompt's wording was tuned.
     * - The question had NO date/keyword signal at all - genuinely
     *   ambiguous, not a confirmed negative. A caller should fall back
     *   to a broader sample and let the AI actually judge in this case,
     *   the same as before.
     *
     * This method only checks whether a signal EXISTS in the question -
     * it does not touch the database, so it's cheap to call before
     * deciding which of those two situations applies.
     */
    public function hasSearchableSignal(string $question): bool
    {
        return $this->extractDateQuery($question) !== null || $this->extractKeywords($question) !== [];
    }

    /**
     * whereJsonContains(), not a raw JSON_CONTAINS()/LIKE - Laravel
     * compiles this to the correct native JSON-membership syntax per
     * database driver (MySQL's JSON_CONTAINS in production, SQLite's
     * json_each-based equivalent in this app's own test suite), so it
     * works portably without this class needing to know which database
     * it's talking to. Checks for an EXACT scalar match within the row's
     * data array, not a substring - unlike queryByKeywords() below, a
     * date match needs to be exact (a LIKE '%46224%' could spuriously
     * match an unrelated value like "146224").
     */
    private function queryByDate(string $department, DateQueryMatch $match, ?int $sourceId): Collection
    {
        return DataRecord::query()
            // Eager-loaded for formatRawRows()'s per-source/sheet group
            // headers - without this, labeling each group would trigger
            // a lazy-loaded query per distinct source, not per row, so
            // still cheap either way, but free to avoid outright.
            ->with('source')
            ->where('department', $department)
            ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
            ->where(function ($query) use ($match): void {
                foreach ($match->strings as $string) {
                    $query->orWhereJsonContains('data', $string);
                }

                $query->orWhereJsonContains('data', $match->excelSerial);
            })
            ->limit(self::MAX_ROWS_TO_AI)
            ->get();
    }

    /**
     * Unlike queryByDate()'s exact membership check, keyword matching is
     * inherently a SUBSTRING operation (the same as keywordScore()'s
     * in-memory str_contains()) - a keyword can legitimately be part of
     * a longer cell value, so this uses LIKE '%keyword%', not an exact
     * JSON-value match. extractKeywords() only ever produces
     * letters/digits (see its own docblock), so there's no LIKE
     * wildcard-injection risk from special characters riding along in a
     * "keyword".
     *
     * LOWER(data) LIKE, NOT a plain `where('data', 'like', ...)` - a
     * real, confirmed bug: extractKeywords() lowercases every keyword
     * (mb_strtolower(), matching keywordScore()'s own lowercasing below),
     * but a plain LIKE against this app's real database is
     * CASE-SENSITIVE for a JSON column - confirmed directly: searching
     * for "bom" found ZERO rows even though "(BOM)" is literally present
     * in a real synced PDF's text, purely because of the case mismatch.
     * With zero SQL candidates ever fetched, keywordScore()'s own
     * (correctly case-insensitive) re-ranking below never even got a
     * chance to run - the row was never wrong, it was simply never
     * fetched. LOWER() is a portable, standard SQL function on both this
     * app's real database drivers (MySQL/SQLite), so this needs no
     * driver-specific branching the way whereJsonContains() sometimes
     * does elsewhere in this class.
     *
     * The SQL pass here is for RECALL only (find every row containing
     * ANY of the keywords, across the whole department, unbounded by
     * recency) - the actual top MAX_ROWS_TO_AI is then picked by
     * re-scoring those candidates with the exact same keywordScore()
     * logic selectRelevantRows() already uses for an in-memory
     * Collection, so ranking behavior is identical between both code
     * paths, not a second, potentially-diverging implementation of
     * "relevance".
     *
     * @param  list<string>  $keywords
     * @return Collection<int, DataRecord>
     */
    private function queryByKeywords(string $department, array $keywords, ?int $sourceId): Collection
    {
        $candidates = DataRecord::query()
            ->where('department', $department)
            ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
            ->where(function ($query) use ($keywords): void {
                foreach ($keywords as $keyword) {
                    $query->orWhereRaw('LOWER(data) LIKE ?', ['%'.$keyword.'%']);
                }
            })
            ->limit(self::KEYWORD_CANDIDATE_LIMIT)
            ->get();

        $matches = $candidates
            ->map(fn (DataRecord $r): array => ['record' => $r, 'score' => $this->keywordScore($r, $keywords)])
            ->filter(fn (array $pair): bool => $pair['score'] > 0)
            ->sortByDesc(fn (array $pair): int => $pair['score'])
            ->take(self::MAX_ROWS_TO_AI)
            ->map(fn (array $pair): DataRecord => $pair['record'])
            ->values();

        return $this->withPdfContext($matches);
    }

    /**
     * For every matched row that came from a PDF source (sheet_name ===
     * PDF_SHEET_NAME - see PdfTextImport), also pulls in a small window
     * of that SAME source's immediately surrounding lines by row_index -
     * a real, confirmed gap: asking "define BOM" matched only a PDF's
     * HEADING line ("Bill of Material (BOM) 5-9"), never the very next
     * line's actual Arabic definition paragraph, since that paragraph
     * never repeats the literal word "BOM" a keyword search matches on.
     * PDF rows are literal, sequential lines of one document (see
     * PdfTextImport's docblock - line numbers are assigned sequentially
     * to real content, in original document order), so a row's immediate
     * neighbors genuinely ARE that row's surrounding context, unlike an
     * Excel row, whose neighbors are just other unrelated data records -
     * this only ever expands PDF rows, Excel rows are returned exactly
     * as matched.
     *
     * Original keyword matches are kept even if this pushes the total
     * past MAX_ROWS_TO_AI slightly over what selectRelevantRows() would
     * otherwise cap to on a later pass - concat() + unique('id') here
     * keeps the certain, scored matches FIRST and context rows after, so
     * a final ->take(MAX_ROWS_TO_AI) (matching buildPrompt()'s own cap)
     * only ever trims added context, never a real match, if the combined
     * set somehow got large enough for that to matter.
     *
     * @param  Collection<int, DataRecord>  $matches
     * @return Collection<int, DataRecord>
     */
    private function withPdfContext(Collection $matches): Collection
    {
        $pdfMatches = $matches->filter(fn (DataRecord $r): bool => $r->sheet_name === self::PDF_SHEET_NAME);

        if ($pdfMatches->isEmpty()) {
            return $matches;
        }

        $contextRecords = collect();

        foreach ($pdfMatches->groupBy('source_id') as $sourceId => $group) {
            $wantedRowIndexes = [];

            foreach ($group->pluck('row_index') as $rowIndex) {
                for ($i = $rowIndex - self::PDF_CONTEXT_ROWS; $i <= $rowIndex + self::PDF_CONTEXT_ROWS; $i++) {
                    $wantedRowIndexes[$i] = true;
                }
            }

            $contextRecords = $contextRecords->concat(
                DataRecord::query()
                    ->where('source_id', $sourceId)
                    ->where('sheet_name', self::PDF_SHEET_NAME)
                    ->whereIn('row_index', array_keys($wantedRowIndexes))
                    ->get()
            );
        }

        return $matches
            ->concat($contextRecords)
            ->unique('id')
            ->take(self::MAX_ROWS_TO_AI)
            ->sortBy([['source_id', 'asc'], ['row_index', 'asc']])
            ->values();
    }

    /**
     * If a date is found in the question, rows containing a matching date
     * value win - "what happened on 1/1/2024" has an objectively correct
     * candidate set, unlike free-text relevance. Otherwise falls back to
     * keyword overlap. Either way, if the chosen strategy finds nothing at
     * all, this still returns *something* (the first MAX_ROWS_TO_AI rows)
     * rather than an empty payload, so the AI can honestly answer "not
     * found in the provided data" instead of the request looking broken.
     *
     * @param  Collection<int, DataRecord>  $records
     * @return Collection<int, DataRecord>
     */
    private function selectRelevantRows(Collection $records, string $question): Collection
    {
        if ($records->count() <= self::MAX_ROWS_TO_AI) {
            return $records;
        }

        $dateMatch = $this->extractDateQuery($question);

        if ($dateMatch !== null) {
            $matches = $records->filter(fn (DataRecord $r) => $this->rowMatchesDate($r, $dateMatch));

            if ($matches->isNotEmpty()) {
                return $matches->take(self::MAX_ROWS_TO_AI)->values();
            }
        }

        $keywords = $this->extractKeywords($question);

        if ($keywords !== []) {
            $scored = $records
                ->map(fn (DataRecord $r) => ['record' => $r, 'score' => $this->keywordScore($r, $keywords)])
                ->filter(fn (array $pair) => $pair['score'] > 0);

            if ($scored->isNotEmpty()) {
                return $scored
                    ->sortByDesc(fn (array $pair) => $pair['score'])
                    ->take(self::MAX_ROWS_TO_AI)
                    ->map(fn (array $pair) => $pair['record'])
                    ->values();
            }
        }

        return $records->take(self::MAX_ROWS_TO_AI)->values();
    }

    /**
     * Looks for a single numeric date-like token in the question
     * ("1/1/2024", "31-12-2024", "2024-01-01") and, if one parses to a
     * real calendar date, returns every raw form that date could appear
     * as inside a DataRecord's values. Sync never reformats anything (see
     * RawRowsImport), so a date cell might be a literal string OR an
     * Excel serial number (e.g. 45658 for 2024-12-31) depending entirely
     * on how the source workbook stored it - both forms have to be
     * checked, or a real match would be silently missed.
     */
    private function extractDateQuery(string $question): ?DateQueryMatch
    {
        if (! preg_match('/\b(\d{1,4})[\/\-.](\d{1,2})[\/\-.](\d{1,4})\b/', $question, $m)) {
            return null;
        }

        $date = $this->parseAmbiguousDate($m[1], $m[2], $m[3]);

        if ($date === null) {
            return null;
        }

        return new DateQueryMatch(
            strings: array_values(array_unique([
                $date->format('Y-m-d'),
                $date->format('n/j/Y'),
                $date->format('m/d/Y'),
                $date->format('j/n/Y'),
                $date->format('d/m/Y'),
                // 2-digit-year forms too - this app's real data
                // consistently stores dates this way (e.g. "21/07/26"),
                // not just 4-digit-year strings, so a match has to check
                // both or a real date cell would be silently missed.
                $date->format('d/m/y'),
                $date->format('n/j/y'),
                $date->format('m/d/y'),
                $date->format('j/n/y'),
            ])),
            excelSerial: (int) Carbon::create(1899, 12, 30)->diffInDays($date),
        );
    }

    /**
     * The three numeric groups in a date-like token are ambiguous
     * ("1/1/2024" reads the same either way, but "3/6/2026" doesn't -
     * March 6 AND June 3 are BOTH valid calendar dates) - rather than
     * guess with no basis, this requires one group to unambiguously
     * anchor the year (either a 4-digit group, or - see below - a
     * trailing 2-digit one), then tries the two possible orderings for
     * the other two, keeping whichever produces a real calendar date
     * (an invalid one, e.g. month 31, is rejected via a strict
     * round-trip check rather than silently rolling over to a different
     * date the way DateTime normally would).
     *
     * DAY-then-month is tried BEFORE month-then-day - this order is not
     * arbitrary, and getting it backwards was a real, confirmed bug:
     * this app's actual synced data consistently uses DAY-first
     * ("21/07/26" = 21 July, confirmed directly against real files -
     * see class docblock), so when a question's two non-year numbers
     * are BOTH valid as either a month or a day (like "3/6/26"), the
     * day-first reading is the one that actually matches this app's
     * real convention. The previous ordering tried month-first FIRST,
     * so "3/6/26" resolved to March 6 (a date this app's data never
     * has, since only day-first dates exist) instead of June 3 (the
     * one actually being asked about) - the search then correctly found
     * zero rows for the wrong date and silently fell through to a
     * broad keyword match instead, which is why the answer described
     * "June" generally rather than the 3rd specifically.
     *
     * A trailing 2-digit year ("21/07/26") is handled as its own case,
     * not just "no 4-digit group found → give up" - this was a real,
     * confirmed bug: this app's actual synced data consistently uses
     * exactly this DD/MM/YY format (confirmed directly against real
     * files), and so does every real question asked about it, but the
     * original 4-digit-only parser silently failed to recognize ANY of
     * them as a date at all, falling through to a much weaker keyword
     * search every single time - not an edge case, but this app's
     * actual normal usage. Assumes 20XX since every date this app has
     * ever seen falls in that century; a genuinely 19XX or 21XX date
     * would need explicit 4-digit input, which still works via the
     * branches above.
     */
    private function parseAmbiguousDate(string $a, string $b, string $c): ?Carbon
    {
        if (strlen($a) === 4) {
            [$year, $first, $second] = [$a, $b, $c];
        } elseif (strlen($c) === 4) {
            [$year, $first, $second] = [$c, $a, $b];
        } elseif (strlen($c) === 2) {
            [$year, $first, $second] = ['20'.$c, $a, $b];
        } else {
            return null;
        }

        foreach ([[$second, $first], [$first, $second]] as [$month, $day]) {
            $candidate = sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
            $parsed = Carbon::createFromFormat('!Y-m-d', $candidate);

            if ($parsed instanceof Carbon && $parsed->format('Y-m-d') === $candidate) {
                return $parsed;
            }
        }

        return null;
    }

    private function rowMatchesDate(DataRecord $record, DateQueryMatch $match): bool
    {
        foreach ($record->data as $value) {
            if (is_string($value) && in_array($value, $match->strings, true)) {
                return true;
            }

            if (is_int($value) || is_float($value)) {
                if ((int) $value === $match->excelSerial) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * \p{L}/\p{N} (Unicode letter/number categories, with the /u
     * modifier), not [a-z0-9] - this data isn't all English. A PDF
     * source's extracted text can be in any script (Arabic, in this
     * app's real usage - see PdfTextImport), and the old ASCII-only
     * pattern matched literally nothing in a non-Latin-script question,
     * silently returning zero keywords - meaning keyword relevance
     * filtering could never target that content for a question asked in
     * its own language. Confirmed as a real bug against this app's live
     * data, not a theoretical one.
     *
     * @return list<string>
     */
    private function extractKeywords(string $question): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $question, $found);

        return collect($found[0])
            ->map(fn (string $word): string => mb_strtolower($word, 'UTF-8'))
            // mb_strlen(), not strlen() - a non-Latin-script character is
            // routinely more than one byte in UTF-8 (Arabic is 2), so a
            // byte-length check silently applies a different, wrong
            // effective character-count threshold per script.
            ->filter(fn (string $word): bool => mb_strlen($word, 'UTF-8') >= 3 && ! in_array($word, self::STOPWORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $keywords
     */
    private function keywordScore(DataRecord $record, array $keywords): int
    {
        // mb_strtolower(), not strtolower() - the latter only touches
        // ASCII a-z, leaving non-Latin text (and non-ASCII Latin
        // accented characters) completely unaffected either way, which
        // happens to still work for pure-Arabic comparisons (Arabic has
        // no case) but is the wrong general tool now that this compares
        // against extractKeywords()'s own mb_strtolower() output.
        $haystack = mb_strtolower(implode(' ', array_map(
            fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $record->data,
        )), 'UTF-8');

        $score = 0;

        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * JSON_UNESCAPED_UNICODE matters beyond cosmetics here - without it,
     * json_encode() escapes every Arabic character to a "\uXXXX"
     * sequence, which the anti-hallucination rules above ask the AI to
     * compare and reason about precisely ("NEVER invent a specific fact,
     * name... that is not literally present in the provided data") -
     * harder to do reliably against an escaped sequence than the actual
     * characters. Found via a real bug in a sibling class
     * (RawRowPayload::json(), used by this app's other AI services) that
     * had the identical missing flag - see that class's docblock.
     *
     * @param  Collection<int, DataRecord>  $records
     */
    private function buildJsonPayload(Collection $records): string
    {
        $datasets = $records
            ->groupBy('source_id')
            ->map(function (Collection $sourceRows): array {
                $first = $sourceRows->first();

                return [
                    'source' => $first->source?->name,
                    'rows' => $sourceRows
                        ->map(fn (DataRecord $record): array => [
                            'sheet_name' => $record->sheet_name,
                            'row_index' => $record->row_index,
                            'values' => $record->data,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $json = json_encode(['datasets' => $datasets], $flags);

        // Never let a malformed value turn into an exception here - fall
        // back to an empty, still-valid payload instead.
        return $json !== false ? $json : json_encode(['datasets' => []], $flags);
    }
}
