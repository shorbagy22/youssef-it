<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Services\ChatDataService;

test('buildPrompt includes the fixed system prompt verbatim', function () {
    $prompt = (new ChatDataService)->buildPrompt(collect(), 'What is this data?');

    expect($prompt)->toContain('You are a data analysis AI working with raw data extracted from Excel and PDF sources.')
        ->and($prompt)->toContain('Do NOT assume fixed columns')
        ->and($prompt)->toContain('Work with raw row/column arrays')
        ->and($prompt)->toContain('Use only the provided data')
        ->and($prompt)->toContain('Answer directly and concisely')
        ->and($prompt)->toContain('not proof the source document')
        ->and($prompt)->toContain('NEVER invent a specific fact, name')
        ->and($prompt)->toContain('Give exactly ONE answer');
});

test('buildPrompt sends an empty but valid datasets array when there is nothing synced', function () {
    $prompt = (new ChatDataService)->buildPrompt(collect(), 'What is this data?');

    expect($prompt)->toContain('"datasets": []')
        ->and($prompt)->toContain("QUESTION:\nWhat is this data?");
});

function decodeChatPromptData(string $prompt): array
{
    $jsonStart = strpos($prompt, "DATA:\n") + strlen("DATA:\n");
    $jsonEnd = strpos($prompt, "\n\nQUESTION:");

    return json_decode(substr($prompt, $jsonStart, $jsonEnd - $jsonStart), true);
}

test('buildPrompt groups rows back into their source dataset, in row order', function () {
    $source = App\Models\Source::factory()->create(['name' => 'Quality Report']);

    $records = collect([
        DataRecord::factory()->make(['source_id' => $source->id, 'sheet_name' => 'Data', 'row_index' => 1, 'data' => ['Date', 'NRFT']]),
        DataRecord::factory()->make(['source_id' => $source->id, 'sheet_name' => 'Data', 'row_index' => 2, 'data' => ['2026-05-01', 95.5]]),
    ]);
    $records->each(fn (DataRecord $r) => $r->setRelation('source', $source));

    $prompt = (new ChatDataService)->buildPrompt($records, 'What is NRFT in May?');
    $decoded = decodeChatPromptData($prompt);

    expect($decoded['datasets'])->toHaveCount(1)
        ->and($decoded['datasets'][0]['source'])->toBe('Quality Report')
        ->and($decoded['datasets'][0]['rows'])->toHaveCount(2)
        ->and($decoded['datasets'][0]['rows'][0])->toEqual(['sheet_name' => 'Data', 'row_index' => 1, 'values' => ['Date', 'NRFT']])
        ->and($decoded['datasets'][0]['rows'][1]['values'])->toEqual(['2026-05-01', 95.5]);
});

test('buildPrompt keeps rows from different sources in separate datasets', function () {
    $sourceA = App\Models\Source::factory()->create(['name' => 'Source A']);
    $sourceB = App\Models\Source::factory()->create(['name' => 'Source B']);

    $a = DataRecord::factory()->make(['source_id' => $sourceA->id]);
    $a->setRelation('source', $sourceA);
    $b = DataRecord::factory()->make(['source_id' => $sourceB->id]);
    $b->setRelation('source', $sourceB);

    $prompt = (new ChatDataService)->buildPrompt(collect([$a, $b]), 'Question?');
    $decoded = decodeChatPromptData($prompt);

    expect($decoded['datasets'])->toHaveCount(2);
});

/**
 * @param  array<int, mixed>  $data
 */
function makeUnsavedRecord(App\Models\Source $source, int $rowIndex, array $data): DataRecord
{
    $record = DataRecord::factory()->make(['source_id' => $source->id, 'row_index' => $rowIndex, 'data' => $data]);
    $record->setRelation('source', $source);

    return $record;
}

test('buildPrompt caps a large dataset at 100 rows even with no matching signal', function () {
    $source = App\Models\Source::factory()->create();

    $records = collect(range(1, 150))
        ->map(fn (int $i) => makeUnsavedRecord($source, $i, ['filler', $i]));

    $prompt = (new ChatDataService)->buildPrompt($records, 'Give me a general summary');
    $decoded = decodeChatPromptData($prompt);

    expect($decoded['datasets'][0]['rows'])->toHaveCount(100);
});

test('buildPrompt does not filter a dataset that already fits under the cap', function () {
    $source = App\Models\Source::factory()->create();

    $records = collect(range(1, 50))
        ->map(fn (int $i) => makeUnsavedRecord($source, $i, ['filler', $i]));

    $prompt = (new ChatDataService)->buildPrompt($records, 'Give me a general summary');
    $decoded = decodeChatPromptData($prompt);

    expect($decoded['datasets'][0]['rows'])->toHaveCount(50);
});

test('buildPrompt prioritizes rows matching a date found in the question, over 100 filler rows', function () {
    $source = App\Models\Source::factory()->create();

    $records = collect(range(1, 150))
        ->map(fn (int $i) => makeUnsavedRecord($source, $i, ['2026-01-01', 'noise']));

    // The one real needle: the Excel serial number for 1/1/2024 (45292),
    // the literal form sync would store for a genuine date cell.
    $needle = makeUnsavedRecord($source, 999, [45292, 'the one that matters']);
    $records->push($needle);

    $prompt = (new ChatDataService)->buildPrompt($records, 'What happened on 1/1/2024?');
    $decoded = decodeChatPromptData($prompt);

    $rows = $decoded['datasets'][0]['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['values'])->toEqual([45292, 'the one that matters']);
});

test('buildPrompt matches a date query against a string date cell too, not just Excel serials', function () {
    $source = App\Models\Source::factory()->create();

    $records = collect(range(1, 150))
        ->map(fn (int $i) => makeUnsavedRecord($source, $i, ['unrelated', $i]));

    $needle = makeUnsavedRecord($source, 999, ['2024-01-01', 'the string-date row']);
    $records->push($needle);

    $prompt = (new ChatDataService)->buildPrompt($records, 'What happened on 1/1/2024?');
    $decoded = decodeChatPromptData($prompt);

    $rows = $decoded['datasets'][0]['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['values'])->toEqual(['2024-01-01', 'the string-date row']);
});

test('buildPrompt falls back to keyword relevance when the question has no date', function () {
    $source = App\Models\Source::factory()->create();

    // Filler rows share no keyword with the question at all, so only
    // the needle should score above zero and survive filtering - a
    // keyword match is precise, not padded back out to the cap the way
    // the "no signal at all" fallback is.
    $records = collect(range(1, 150))
        ->map(fn (int $i) => makeUnsavedRecord($source, $i, ['unrelated', $i]));

    $needle = makeUnsavedRecord($source, 999, ['widget', 'inventory count']);
    $records->push($needle);

    $prompt = (new ChatDataService)->buildPrompt($records, 'How many widgets are in inventory?');
    $decoded = decodeChatPromptData($prompt);

    $rows = collect($decoded['datasets'][0]['rows']);

    expect($rows)->toHaveCount(1)
        ->and($rows->firstWhere('values', ['widget', 'inventory count']))->not->toBeNull();
});

test('buildPrompt matches keyword relevance for a non-English (Arabic) question, not just ASCII ones', function () {
    $source = App\Models\Source::factory()->create();

    // Filler rows share no keyword with the question at all - same
    // structure as the ASCII keyword-fallback test above, just proving
    // the same mechanism also works for a script the old ASCII-only
    // regex silently couldn't tokenize at all (a real bug found against
    // this app's live data: an Arabic PDF source's content could never
    // be keyword-matched against an Arabic question).
    $records = collect(range(1, 150))
        ->map(fn (int $i) => makeUnsavedRecord($source, $i, ['unrelated', $i]));

    $needle = makeUnsavedRecord($source, 999, ['line' => 1, 'text' => 'الإجراءات المطلوبة']);
    $records->push($needle);

    $prompt = (new ChatDataService)->buildPrompt($records, 'ما هي الإجراءات المطلوبة؟');
    $decoded = decodeChatPromptData($prompt);

    $rows = collect($decoded['datasets'][0]['rows']);

    expect($rows)->toHaveCount(1)
        ->and($rows->firstWhere('values', ['line' => 1, 'text' => 'الإجراءات المطلوبة']))->not->toBeNull();
});

test('findRelevantRecords finds a date match regardless of row recency - regression test for a confirmed bug', function () {
    $source = App\Models\Source::factory()->create();

    // The real row - created FIRST, so it has a LOWER id than every
    // decoy below. A recency-ordered "most recent N rows" pool (what
    // ChatController used to always fetch, and still falls back to)
    // would exclude this once enough newer unrelated rows exist - the
    // whole point of findRelevantRecords() is that it finds this by
    // CONTENT via SQL, never by how recently it was synced.
    $serial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 7, 21));
    $target = App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'data' => [$serial, ' Assembly', 'كسر'],
    ]);

    // Many newer (higher-id) unrelated rows - simulates a large,
    // recently-synced source crowding out an older row.
    App\Models\DataRecord::factory()->count(60)->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'data' => ['unrelated', 'filler'],
    ]);

    $records = (new ChatDataService)->findRelevantRecords('quality', 'what happened on 21/7/2026?');

    expect($records)->not->toBeNull()
        ->and($records->pluck('id'))->toContain($target->id);
});

test('findRelevantRecords finds a keyword match regardless of row recency', function () {
    $source = App\Models\Source::factory()->create();

    $target = App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'data' => ['line' => 1, 'text' => 'الإجراءات المطلوبة للمعنية'],
    ]);

    App\Models\DataRecord::factory()->count(60)->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'data' => ['unrelated', 'filler'],
    ]);

    $records = (new ChatDataService)->findRelevantRecords('quality', 'ما هي الإجراءات المطلوبة؟');

    expect($records)->not->toBeNull()
        ->and($records->pluck('id'))->toContain($target->id);
});

test('findRelevantRecords finds a keyword match even when the stored text\'s case differs from the question\'s - regression test for a real, confirmed bug', function () {
    // A real, live-confirmed bug: extractKeywords() lowercases every
    // keyword before searching, but a plain SQL LIKE against this app's
    // real database is CASE-SENSITIVE for the JSON "data" column -
    // searching for "bom" found ZERO rows even though "(BOM)" was
    // literally present in a real synced PDF's text. keywordScore()'s
    // own in-memory re-ranking was already correctly case-insensitive,
    // but that never even ran, because the SQL candidate query never
    // fetched the row from the database in the first place.
    $source = App\Models\Source::factory()->create();

    $target = App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'data' => ['line' => 99, 'text' => ': Bill of Material (BOM) 5-9'],
    ]);

    App\Models\DataRecord::factory()->count(60)->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'data' => ['unrelated', 'filler'],
    ]);

    $records = (new ChatDataService)->findRelevantRecords('quality', 'define bom');

    expect($records)->not->toBeNull()
        ->and($records->pluck('id'))->toContain($target->id);
});

test('findRelevantRecords pulls in the lines immediately surrounding a PDF keyword match, not just the matching line itself - regression test for a real, confirmed bug', function () {
    // A real, confirmed gap: "define BOM" matched only a PDF's HEADING
    // line ("Bill of Material (BOM) 5-9"), never the very next line's
    // actual definition paragraph, since that paragraph never repeats
    // the literal word "BOM". PDF rows are sequential lines of one
    // document (see PdfTextImport) - a matched row's immediate
    // row_index neighbors are that row's real surrounding context.
    $source = App\Models\Source::factory()->create();

    $before = App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'pdf',
        'row_index' => 98,
        'data' => ['line' => 98, 'text' => 'some earlier unrelated line'],
    ]);

    $heading = App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'pdf',
        'row_index' => 99,
        'data' => ['line' => 99, 'text' => ': Bill of Material (BOM) 5-9'],
    ]);

    $definition = App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'pdf',
        'row_index' => 100,
        'data' => ['line' => 100, 'text' => 'the actual Arabic definition paragraph, no literal "BOM" in it'],
    ]);

    $farAway = App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'pdf',
        'row_index' => 500,
        'data' => ['line' => 500, 'text' => 'a much later, unrelated line'],
    ]);

    $records = (new ChatDataService)->findRelevantRecords('quality', 'define BOM');

    expect($records)->not->toBeNull();

    $ids = $records->pluck('id');
    expect($ids)->toContain($before->id)
        ->toContain($heading->id)
        ->toContain($definition->id)
        ->not->toContain($farAway->id);
});

test('findRelevantRecords does NOT pull in surrounding rows for an EXCEL keyword match - only PDF rows get context expansion', function () {
    // An Excel row's neighbors are just other unrelated data records,
    // not "the rest of a sentence" the way a PDF line's are - pulling
    // them in as "context" would be actively wrong, not just unhelpful.
    $source = App\Models\Source::factory()->create();

    $match = App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'Total',
        'row_index' => 50,
        'data' => ['item' => 'BOM code', 'qty' => 4],
    ]);

    $neighbor = App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'Total',
        'row_index' => 51,
        'data' => ['item' => 'completely unrelated row', 'qty' => 9],
    ]);

    $records = (new ChatDataService)->findRelevantRecords('quality', 'define bom');

    expect($records)->not->toBeNull();

    $ids = $records->pluck('id');
    expect($ids)->toContain($match->id)
        ->not->toContain($neighbor->id);
});

test('findRelevantRecords returns null when the question has no date or keyword signal at all', function () {
    App\Models\DataRecord::factory()->count(5)->create(['department' => 'quality']);

    $records = (new ChatDataService)->findRelevantRecords('quality', 'ok');

    expect($records)->toBeNull();
});

test('findRelevantRecords only searches the given department', function () {
    $qualitySource = App\Models\Source::factory()->create();
    $itSource = App\Models\Source::factory()->create();

    App\Models\DataRecord::factory()->create([
        'source_id' => $itSource->id,
        'department' => 'it',
        'data' => ['line' => 1, 'text' => 'الإجراءات المطلوبة'],
    ]);

    $records = (new ChatDataService)->findRelevantRecords('quality', 'ما هي الإجراءات المطلوبة؟');

    expect($records)->toBeNull();
});

test('hasSearchableSignal is true for a question containing a date', function () {
    expect((new ChatDataService)->hasSearchableSignal('what happened on 21/7/2026?'))->toBeTrue();
});

test('hasDateSignal recognizes a 2-digit-year date (DD/MM/YY), not just 4-digit years - regression test for a real, confirmed bug', function () {
    // This app's actual synced data consistently uses this exact
    // format ("21/07/26"), and so does every real question asked about
    // it - a 4-digit-year-only parser silently failed to recognize any
    // of them as a date, breaking date matching for this app's normal
    // usage, not an edge case.
    $service = new ChatDataService;

    expect($service->hasDateSignal('tell me the defects happened on 25/6/26 in the assembly area'))->toBeTrue()
        ->and($service->hasDateSignal('what happened on 21/07/26?'))->toBeTrue();
});

test('findByDate finds a row stored with a 2-digit-year date string, matching a 2-digit-year question', function () {
    App\Models\DataRecord::factory()->create([
        'department' => 'quality',
        'data' => ['25/06/26', ' Assembly', 'قطع', 4],
    ]);

    $records = (new ChatDataService)->findByDate('quality', 'tell me the defects happened on 25/6/26 in the assembly area');

    expect($records)->not->toBeNull()
        ->and($records->count())->toBe(1);
});

test('formatRawRows renders each row as one labelless field per line, under a source/sheet header', function () {
    // No header row was found (only ->make(), never persisted) - each
    // field just shows its raw value, one per line.
    $source = App\Models\Source::factory()->create(['name' => 'area scrap']);
    $record = App\Models\DataRecord::factory()->make(['source_id' => $source->id, 'sheet_name' => 'Total', 'data' => [' Assembly', 'قطع', 4, null, 'FGM']]);
    $record->setRelation('source', $source);

    $text = (new ChatDataService)->formatRawRows(collect([$record]), 'anything');

    expect($text)->toBe("=== area scrap / Total ===\n\n Assembly\nقطع\n4\nFGM");
});

test('formatRawRows renders multiple rows from the SAME source/sheet as separate blocks, one blank line apart', function () {
    $source = App\Models\Source::factory()->create(['name' => 'area scrap']);
    $a = App\Models\DataRecord::factory()->make(['source_id' => $source->id, 'sheet_name' => 'Total', 'data' => ['row', 1]]);
    $a->setRelation('source', $source);
    $b = App\Models\DataRecord::factory()->make(['source_id' => $source->id, 'sheet_name' => 'Total', 'data' => ['row', 2]]);
    $b->setRelation('source', $source);

    $text = (new ChatDataService)->formatRawRows(collect([$a, $b]), 'anything');

    expect($text)->toBe("=== area scrap / Total ===\n\nrow\n1\n\nrow\n2");
});

test('formatRawRows groups rows from DIFFERENT sheets under SEPARATE labeled headers, never flattened together - regression test for a real, confirmed bug', function () {
    // A date match can span sheets with completely different column
    // meanings - dumping them as one undifferentiated table would
    // silently imply column 0 means the same thing in both, when it
    // doesn't. This was reported live: rows from a "Cookers" production
    // sheet and an "Assembly" defect sheet, both matching the same
    // date, got run together with no indication they weren't the same
    // table shape.
    $source = App\Models\Source::factory()->create(['name' => 'area scrap']);
    $totalRow = App\Models\DataRecord::factory()->make(['source_id' => $source->id, 'sheet_name' => 'Total', 'data' => [' Assembly', 'قطع']]);
    $totalRow->setRelation('source', $source);
    $cookersRow = App\Models\DataRecord::factory()->make(['source_id' => $source->id, 'sheet_name' => 'Cookers', 'data' => ['Line1', 'Added Productivity']]);
    $cookersRow->setRelation('source', $source);

    $text = (new ChatDataService)->formatRawRows(collect([$totalRow, $cookersRow]), 'anything');

    expect($text)->toBe(
        "=== area scrap / Total ===\n\n Assembly\nقطع\n\n\n"
        ."=== area scrap / Cookers ===\n\nLine1\nAdded Productivity"
    );
});

test('formatRawRows decodes the matched Excel date serial to a readable d/m/y date, leaving every other number untouched', function () {
    $source = App\Models\Source::factory()->create(['name' => 'area scrap']);
    $serial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 7, 21));
    $record = App\Models\DataRecord::factory()->make(['source_id' => $source->id, 'sheet_name' => 'Total', 'data' => [$serial, 1126312302, ' Assembly']]);
    $record->setRelation('source', $source);

    $text = (new ChatDataService)->formatRawRows(collect([$record]), 'what happened on 21/7/26?');

    // The date serial becomes readable; the unrelated large ID number
    // is left completely raw - only the value that actually matched the
    // question's own date is touched.
    expect($text)->toBe("=== area scrap / Total ===\n\n21/07/26\n1126312302\n Assembly");
});

test('formatRawRows labels every value inline, one per line, using the sheet\'s real header row, even though that header row is not itself among the matched rows', function () {
    // Follow-up request: a single header line at the top of the group
    // still meant the reader had to count tabs back up to it to know
    // what a given value in a long row meant, and cramming every
    // labeled field onto one long tab-separated line per row read as a
    // wall of text once a row had a dozen-plus fields. Each labeled
    // field now gets its own line instead - "Day: 15/08/24" on its own
    // line, not squeezed edge-to-edge with the next field. This app's
    // real sheets DO have a genuine header row, it's just not
    // necessarily row 1 - formatRawRows() looks it up separately from
    // whatever rows matched the question, since the header row itself
    // would almost never be the row that matches a date search.
    $source = App\Models\Source::factory()->create(['name' => 'daily report']);
    App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'sheet_name' => 'Data',
        'row_index' => 1,
        'data' => ['Day', 'Week', 'Defect'],
    ]);
    $matchedRow = App\Models\DataRecord::factory()->make([
        'source_id' => $source->id,
        'sheet_name' => 'Data',
        'row_index' => 2,
        'data' => ['15/08/24', 33, 'Fault Assembly'],
    ]);
    $matchedRow->setRelation('source', $source);

    $text = (new ChatDataService)->formatRawRows(collect([$matchedRow]), 'anything');

    expect($text)->toBe("=== daily report / Data ===\n\nDay: 15/08/24\nWeek: 33\nDefect: Fault Assembly");
});

test('formatRawRows skips a leading totals/summary row of bare numbers and labels using the REAL text header row beneath it', function () {
    // Confirmed against this app's real data: several sheets have a
    // grand-total row of bare numbers ABOVE the actual header row, e.g.
    // [null, null, 550496, 15140] followed by ["Day", "Week", ...] -
    // that totals row must never be mistaken for the header.
    $source = App\Models\Source::factory()->create(['name' => 'daily report']);
    App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'sheet_name' => 'Data',
        'row_index' => 1,
        'data' => [null, null, 550496, 15140],
    ]);
    App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'sheet_name' => 'Data',
        'row_index' => 2,
        'data' => ['Day', 'Week', 'Production', 'Defects'],
    ]);
    $matchedRow = App\Models\DataRecord::factory()->make([
        'source_id' => $source->id,
        'sheet_name' => 'Data',
        'row_index' => 3,
        'data' => ['15/08/24', 33, 116, 1],
    ]);
    $matchedRow->setRelation('source', $source);

    $text = (new ChatDataService)->formatRawRows(collect([$matchedRow]), 'anything');

    expect($text)->toBe("=== daily report / Data ===\n\nDay: 15/08/24\nWeek: 33\nProduction: 116\nDefects: 1");
});

test('formatRawRows drops a null cell entirely instead of showing an empty "Label: " line for it', function () {
    $source = App\Models\Source::factory()->create(['name' => 'daily report']);
    App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'sheet_name' => 'Data',
        'row_index' => 1,
        'data' => ['Day', 'Week', 'Defect'],
    ]);
    $matchedRow = App\Models\DataRecord::factory()->make([
        'source_id' => $source->id,
        'sheet_name' => 'Data',
        'row_index' => 2,
        'data' => ['15/08/24', null, 'Fault Assembly'],
    ]);
    $matchedRow->setRelation('source', $source);

    $text = (new ChatDataService)->formatRawRows(collect([$matchedRow]), 'anything');

    expect($text)->toBe("=== daily report / Data ===\n\nDay: 15/08/24\nDefect: Fault Assembly");
});

test('formatRawRows shows a cell past the end of the known header without a label, rather than inventing one', function () {
    $source = App\Models\Source::factory()->create(['name' => 'daily report']);
    App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'sheet_name' => 'Data',
        'row_index' => 1,
        'data' => ['Day', 'Week'],
    ]);
    $matchedRow = App\Models\DataRecord::factory()->make([
        'source_id' => $source->id,
        'sheet_name' => 'Data',
        'row_index' => 2,
        'data' => ['15/08/24', 33, 'an extra unlabeled value'],
    ]);
    $matchedRow->setRelation('source', $source);

    $text = (new ChatDataService)->formatRawRows(collect([$matchedRow]), 'anything');

    expect($text)->toBe("=== daily report / Data ===\n\nDay: 15/08/24\nWeek: 33\nan extra unlabeled value");
});

test('formatRawRows omits labels when no row in the sheet confidently looks like a header', function () {
    $source = App\Models\Source::factory()->create(['name' => 'chart data']);
    App\Models\DataRecord::factory()->create([
        'source_id' => $source->id,
        'sheet_name' => 'Chart',
        'row_index' => 1,
        'data' => [1, 2, 3],
    ]);
    $matchedRow = App\Models\DataRecord::factory()->make([
        'source_id' => $source->id,
        'sheet_name' => 'Chart',
        'row_index' => 2,
        'data' => [4, 5, 6],
    ]);
    $matchedRow->setRelation('source', $source);

    $text = (new ChatDataService)->formatRawRows(collect([$matchedRow]), 'anything');

    expect($text)->toBe("=== chart data / Chart ===\n\n4\n5\n6");
});

test('findByDate finds a row stored as an Excel serial number, matching a 2-digit-year question', function () {
    $serial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 6, 25));

    App\Models\DataRecord::factory()->create([
        'department' => 'quality',
        'data' => [$serial, ' Assembly', 'قطع', 4],
    ]);

    $records = (new ChatDataService)->findByDate('quality', 'tell me the defects happened on 25/6/26 in the assembly area');

    expect($records)->not->toBeNull()
        ->and($records->count())->toBe(1);
});

test('an ambiguous date like 3/6/26 resolves as day-first (June 3), not month-first (March 6) - regression test for a real, live-confirmed bug', function () {
    // "3/6/26" is genuinely ambiguous: BOTH "3 June" and "March 6" are
    // valid calendar dates, unlike "25/6/26" where month=25 is
    // impossible and forces day-first regardless of priority. This is
    // the case that exposed the bug live: the parser tried month-first
    // FIRST, resolved to March 6 (a date that never appears in this
    // app's real data), found zero matches, and silently fell through
    // to a broad keyword search - producing an answer about "June"
    // generally instead of the 3rd specifically, without ever telling
    // the user a different date had been substituted.
    $juneThirdSerial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 6, 3));
    $marchSixthSerial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 3, 6));

    $target = App\Models\DataRecord::factory()->create([
        'department' => 'quality',
        'data' => [$juneThirdSerial, ' Assembly', 'كسر', 2],
    ]);

    // A decoy for the WRONG (month-first) interpretation - if this gets
    // matched instead, the bug has come back.
    App\Models\DataRecord::factory()->create([
        'department' => 'quality',
        'data' => [$marchSixthSerial, ' Assembly', 'قطع', 9],
    ]);

    $records = (new ChatDataService)->findByDate('quality', 'defects on 3/6/26 in assembly');

    expect($records)->not->toBeNull()
        ->and($records->count())->toBe(1)
        ->and($records->first()->id)->toBe($target->id);
});

test('hasSearchableSignal is true for a question containing a meaningful keyword, even with no date', function () {
    expect((new ChatDataService)->hasSearchableSignal('show me the assembly defects'))->toBeTrue();
});

test('hasSearchableSignal is false for a question with no date and only stopwords/short words', function () {
    expect((new ChatDataService)->hasSearchableSignal('ok what is up'))->toBeFalse();
});
