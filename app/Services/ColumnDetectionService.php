<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Deterministically detects which raw spreadsheet columns represent a
 * date, an area/department, a defect type, and a quantity - based
 * purely on VALUE PATTERNS sampled across many rows, never on column
 * names or a fixed layout (there is no header to read even if one
 * existed in the source file - this app's sync pipeline never treats
 * any row as special, see RawRowsImport).
 *
 * This exists to make a narrow, well-defined "which column is X"
 * question answerable WITHOUT an AI call: instant, free, and fully
 * reproducible for the same input - unlike asking a model to infer
 * structure, which this app's AI-backed services (ChatDataService,
 * DefectAnalysisService, DataAnalysisService) still do for genuinely
 * open-ended interpretation this class doesn't attempt.
 *
 * Inherent limitation, stated honestly rather than glossed over: with
 * no headers, some ambiguity is unresolvable by value pattern alone
 * (e.g. two different small-integer columns, only one of which is
 * really "quantity"). The scoring below uses the best available
 * secondary signals to break such ties (variability for quantity,
 * cardinality/length/script for area vs defect) but this is a
 * heuristic, not a proof - a genuinely ambiguous file can still detect
 * the wrong column. detectColumns() returns null for a category it
 * isn't reasonably confident about, rather than guessing.
 */
final class ColumnDetectionService
{
    // Sampling a bounded, evenly-spaced subset keeps detectColumns()
    // fast on a huge sheet (tens of thousands of rows, as this app's
    // real synced data reaches - see RawRowsImport) without ever
    // scanning the whole thing just to learn its column shape.
    private const int MAX_SAMPLE_ROWS = 500;

    private const int MIN_SAMPLES_FOR_CATEGORY = 3;

    // A value is only trusted as a low-cardinality "category label"
    // (area or defect) if it has at most this many DISTINCT values
    // across the sample - a real-world taxonomy (department names,
    // defect types) is typically a handful to a couple dozen
    // categories, REGARDLESS of how many rows were sampled. This is
    // deliberately an absolute count, not a ratio of sample size: a
    // ratio-based cutoff was tried first and had a real, confirmed bug
    // - a genuine 4-category column scores as "high cardinality" at a
    // small sample size (4 distinct / 10 rows = 0.4) even though the
    // exact same column is obviously fine at a larger one (4/60 =
    // 0.067), which is backwards - the number of real categories in a
    // column doesn't depend on how many rows happened to be sampled.
    private const int MAX_CATEGORY_DISTINCT_VALUES = 30;

    // The ratio check below only serves a DIFFERENT purpose than the
    // absolute cap above: catching genuinely unique-per-row free text
    // (e.g. a notes column where nearly every value differs) - it only
    // applies once the sample is large enough for a ratio to mean
    // anything, so it can't fire on the same small-sample data the
    // absolute cap already handles correctly.
    private const int MIN_SAMPLES_FOR_CARDINALITY_RATIO_CHECK = 20;

    private const float MAX_CATEGORY_CARDINALITY_RATIO = 0.7;

    private const float MIN_CONFIDENCE = 0.3;

    /**
     * Excel's own date serial range for roughly 2009-2036 - this app's
     * real synced data has repeatedly shown dates stored as this raw
     * numeric form rather than a formatted string (see RawRowsImport/
     * ChatDataService), so a purely string-pattern date check alone
     * would silently miss a real date column.
     */
    private const int EXCEL_SERIAL_DATE_MIN = 40000;

    private const int EXCEL_SERIAL_DATE_MAX = 50000;

    /**
     * @param  list<array{row_index: int, values: list<mixed>}>  $rows
     * @return array{date_index: ?int, area_index: ?int, defect_index: ?int, quantity_index: ?int}
     */
    public function detectColumns(array $rows): array
    {
        $sample = $this->sampleRows($rows);
        $columnCount = $this->maxColumnCount($sample);

        if ($columnCount === 0) {
            return ['date_index' => null, 'area_index' => null, 'defect_index' => null, 'quantity_index' => null];
        }

        // One list of non-null values per column, gathered once and
        // reused by every scorer below, rather than re-walking $sample
        // once per category.
        $columnValues = $this->collectColumnValues($sample, $columnCount);

        $scores = [
            'date' => [],
            'quantity' => [],
            'area' => [],
            'defect' => [],
        ];

        foreach ($columnValues as $index => $values) {
            $scores['date'][$index] = $this->scoreDate($values);
            $scores['quantity'][$index] = $this->scoreQuantity($values);
            $scores['area'][$index] = $this->scoreCategoryLabel($values, preferArabic: false);
            $scores['defect'][$index] = $this->scoreCategoryLabel($values, preferArabic: true);
        }

        // Assign the most unambiguous signals first (date, quantity),
        // then the more nuanced string-pattern ones (area, defect) -
        // each assignment removes that column from every other
        // category's candidate pool, so no two categories can end up
        // pointing at the same column index.
        $assigned = [];
        $result = [];

        foreach (['date', 'quantity', 'area', 'defect'] as $category) {
            $index = $this->pickBestColumn($scores[$category], $assigned);
            $result["{$category}_index"] = $index;

            if ($index !== null) {
                $assigned[$index] = true;
            }
        }

        return $result;
    }

    /**
     * Maps raw {row_index, values} rows into structured records using
     * the columns detectColumns() found - a plain per-row projection,
     * O(n) in the row count and cheap regardless of dataset size (all
     * the expensive pattern analysis already happened once, in
     * detectColumns(), against a bounded sample - this does not
     * re-analyze anything).
     *
     * Every input row produces an output record, even if a category
     * wasn't confidently detected (its value is null) or a specific
     * cell in that row is empty - rows are never dropped here, only
     * individual fields may come back null.
     *
     * @param  list<array{row_index: int, values: list<mixed>}>  $rows
     * @param  array{date_index: ?int, area_index: ?int, defect_index: ?int, quantity_index: ?int}  $columns
     * @return list<array{date: ?string, area: ?string, defect: ?string, quantity: int|float|null}>
     */
    public function normalizeRows(array $rows, array $columns): array
    {
        $dateIndex = $columns['date_index'] ?? null;
        $areaIndex = $columns['area_index'] ?? null;
        $defectIndex = $columns['defect_index'] ?? null;
        $quantityIndex = $columns['quantity_index'] ?? null;

        $normalized = [];

        foreach ($rows as $row) {
            $values = $row['values'] ?? [];

            $normalized[] = [
                'date' => $this->cellAsString($values, $dateIndex),
                'area' => $this->cellAsString($values, $areaIndex),
                'defect' => $this->cellAsString($values, $defectIndex),
                'quantity' => $this->cellAsQuantity($values, $quantityIndex),
            ];
        }

        return $normalized;
    }

    /**
     * Evenly-spaced sample across the whole row set, not just the
     * first MAX_SAMPLE_ROWS - a file's early rows aren't necessarily
     * representative of the whole (this app has already seen sheets
     * whose real content is followed by thousands of blank/near-
     * identical rows - see RawRowsImport), so a stride keeps the
     * sample representative regardless of where in the file real
     * variety lives.
     *
     * @param  list<array{row_index: int, values: list<mixed>}>  $rows
     * @return list<array{row_index: int, values: list<mixed>}>
     */
    private function sampleRows(array $rows): array
    {
        $total = count($rows);

        if ($total <= self::MAX_SAMPLE_ROWS) {
            return $rows;
        }

        $stride = (int) ceil($total / self::MAX_SAMPLE_ROWS);
        $sample = [];

        for ($i = 0; $i < $total; $i += $stride) {
            $sample[] = $rows[$i];
        }

        return $sample;
    }

    /**
     * @param  list<array{row_index: int, values: list<mixed>}>  $sample
     */
    private function maxColumnCount(array $sample): int
    {
        $max = 0;

        foreach ($sample as $row) {
            $max = max($max, count($row['values'] ?? []));
        }

        return $max;
    }

    /**
     * @param  list<array{row_index: int, values: list<mixed>}>  $sample
     * @return array<int, list<mixed>> Column index => non-null sampled values.
     */
    private function collectColumnValues(array $sample, int $columnCount): array
    {
        $columns = array_fill(0, $columnCount, []);

        foreach ($sample as $row) {
            $values = $row['values'] ?? [];

            for ($i = 0; $i < $columnCount; $i++) {
                $value = $values[$i] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                $columns[$i][] = $value;
            }
        }

        return $columns;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function scoreDate(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $matches = 0;

        foreach ($values as $value) {
            if ($this->looksLikeDateString($value) || $this->looksLikeExcelDateSerial($value)) {
                $matches++;
            }
        }

        return $matches / count($values);
    }

    private function looksLikeDateString(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        // DD/MM/YY, DD/MM/YYYY, and the same with "-" - the format this
        // app's real Excel sources actually use for a written date cell.
        return preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2}(\d{2})?$/', trim($value)) === 1;
    }

    private function looksLikeExcelDateSerial(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        return (float) $value === floor((float) $value)
            && $value >= self::EXCEL_SERIAL_DATE_MIN
            && $value <= self::EXCEL_SERIAL_DATE_MAX;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function scoreQuantity(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $magnitudeTotal = 0.0;

        foreach ($values as $value) {
            $magnitudeTotal += $this->quantityLikeness($value);
        }

        $magnitudeScore = $magnitudeTotal / count($values);

        if ($magnitudeScore === 0.0) {
            return 0.0;
        }

        // A genuine per-row quantity varies row to row; a small integer
        // that's constant or near-constant across the sample (e.g. a
        // fixed shift/line number that just happens to be a small
        // number) is more likely a categorical code, not an actual
        // count - variability breaks that tie in favor of the column
        // that actually behaves like a count.
        $distinctRatio = count(array_unique(array_map(fn (mixed $v): string => (string) $v, $values))) / count($values);
        $variabilityScore = min(1.0, $distinctRatio * 2);

        return ($magnitudeScore * 0.6) + ($variabilityScore * 0.4);
    }

    /**
     * A per-value score, not just yes/no - a small integer like 15
     * scores highest, a mid-size one (a few thousand) scores partial
     * credit, and a 9-10 digit value (an ID-like column, e.g.
     * "1126312302") scores zero. That magnitude cutoff is what keeps a
     * quantity column from being confused with an ID column, which a
     * bare "is this numeric?" check could not tell apart.
     */
    private function quantityLikeness(mixed $value): float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }

        if (! is_int($value) && ! is_float($value)) {
            return 0.0;
        }

        if ((float) $value !== floor((float) $value) || $value < 0) {
            return 0.0;
        }

        return match (true) {
            $value <= 1000 => 1.0,
            $value <= 10000 => 0.4,
            $value <= 100000 => 0.1,
            default => 0.0,
        };
    }

    /**
     * Both area and defect are "the same handful of short labels repeat
     * over and over" columns - what actually tells them apart in this
     * data is language (an English word like "Assembly" vs an Arabic
     * word like "كسر"), and, among Arabic candidates, shortness (a
     * defect NAME like "كسر" vs a longer explanatory phrase like "اثناء
     * التجميع" that might also repeat but describes rather than
     * categorizes). $preferArabic biases the score toward
     * Arabic-scripted values without requiring them, so a purely
     * non-Arabic-language file still gets a workable area/defect split
     * based on cardinality and length alone.
     *
     * A column with only ONE distinct value across the whole sample is
     * rejected outright (score 0), even though it's technically
     * "low cardinality" - a constant value (e.g. every row says the
     * same fixed plant code) carries no discriminating signal and
     * shouldn't out-score a column that genuinely varies among a
     * handful of real categories.
     *
     * @param  list<mixed>  $values
     */
    private function scoreCategoryLabel(array $values, bool $preferArabic): float
    {
        $strings = array_values(array_filter(
            array_map(fn (mixed $v): ?string => is_string($v) ? trim($v) : null, $values),
            fn (?string $v): bool => $v !== null && $v !== '',
        ));

        if (count($strings) < self::MIN_SAMPLES_FOR_CATEGORY) {
            return 0.0;
        }

        // A column of mostly numbers/dates isn't a text label column,
        // regardless of how few distinct values repeat.
        $numericCount = count(array_filter(
            $strings,
            fn (string $v): bool => is_numeric($v) || $this->looksLikeDateString($v),
        ));

        if ($numericCount / count($strings) > 0.5) {
            return 0.0;
        }

        $distinct = count(array_unique($strings));

        if ($distinct < 2 || $distinct > self::MAX_CATEGORY_DISTINCT_VALUES) {
            return 0.0;
        }

        if (count($strings) >= self::MIN_SAMPLES_FOR_CARDINALITY_RATIO_CHECK) {
            $cardinalityRatio = $distinct / count($strings);

            if ($cardinalityRatio > self::MAX_CATEGORY_CARDINALITY_RATIO) {
                return 0.0;
            }
        }

        // Lower distinct count = more confident category label - an
        // absolute scale, not sample-size-relative (see
        // MAX_CATEGORY_DISTINCT_VALUES's docblock for why).
        $cardinalityScore = max(0.0, 1.0 - (($distinct - 2) / (self::MAX_CATEGORY_DISTINCT_VALUES - 2)));

        $avgLength = array_sum(array_map('mb_strlen', $strings)) / count($strings);
        // Decays toward 0 as average value length grows past ~40
        // characters - a category LABEL is short; a free-text
        // description isn't, even if it happens to repeat sometimes.
        $lengthScore = max(0.0, 1.0 - ($avgLength / 40));

        $arabicRatio = count(array_filter($strings, $this->containsArabic(...))) / count($strings);
        $languageScore = $preferArabic ? $arabicRatio : (1.0 - $arabicRatio);

        // Cardinality alone can't tell two DIFFERENT non-Arabic
        // low-cardinality columns apart (e.g. real area names like
        // "Assembly"/"Packing" vs an unrelated short code column like
        // "FGM"/"RM"/"WIP" that also happens to repeat) - only relevant
        // for the area side of this check (preferArabic: false), since
        // Arabic script has no case to judge by. A short ALL-CAPS token
        // reads as a code/abbreviation; an ordinary word has lowercase
        // letters - a real, general distinction, not specific to any
        // one file's exact values.
        $naturalWordScore = 1.0;

        if (! $preferArabic) {
            $codeLikeRatio = count(array_filter(
                $strings,
                fn (string $v): bool => preg_match('/^[A-Z0-9]{1,5}$/', $v) === 1,
            )) / count($strings);
            $naturalWordScore = 1.0 - $codeLikeRatio;
        }

        // Weighted blend: cardinality is the primary signal for BOTH
        // area and defect, length mainly exists to break a tie between
        // two low-cardinality columns (a short label vs a longer
        // phrase), language is a further tie-breaker between area and
        // defect specifically, and naturalWordScore only ever
        // discriminates among competing non-Arabic candidates (it's a
        // no-op, 1.0, on the Arabic/defect side).
        return ($cardinalityScore * 0.4) + ($lengthScore * 0.2) + ($languageScore * 0.2) + ($naturalWordScore * 0.2);
    }

    private function containsArabic(string $value): bool
    {
        return preg_match('/\p{Arabic}/u', $value) === 1;
    }

    /**
     * @param  array<int, float>  $scoresByColumn
     * @param  array<int, true>  $assigned
     */
    private function pickBestColumn(array $scoresByColumn, array $assigned): ?int
    {
        $bestIndex = null;
        $bestScore = self::MIN_CONFIDENCE;

        foreach ($scoresByColumn as $index => $score) {
            if (isset($assigned[$index])) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function cellAsString(array $values, ?int $index): ?string
    {
        if ($index === null) {
            return null;
        }

        $value = $values[$index] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? trim($value) : (string) $value;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function cellAsQuantity(array $values, ?int $index): int|float|null
    {
        if ($index === null) {
            return null;
        }

        $value = $values[$index] ?? null;

        if (is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return $value;
    }
}
