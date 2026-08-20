<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Models\Department;
use App\Models\Source;
use Illuminate\Support\Facades\Http;

test('it rejects a source that has no "Total" sheet', function () {
    $source = Source::factory()->create();
    DataRecord::factory()->create(['source_id' => $source->id, 'sheet_name' => 'Sheet1']);

    $this->postJson('/api/defects/area-scrap-count', ['source_id' => $source->id, 'message' => 'ok'])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});

test('it finds the matching date row via SQL search, scoped to this source and the Total sheet only', function () {
    $source = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);

    $serial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 7, 21));

    $target = DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'Total',
        'data' => [$serial, 1126312302, 7, 943006420, ' Assembly', 809152438, 'item desc', 4, 'قطع'],
    ]);

    // Decoys: many newer rows in the same sheet with no match, so this
    // isn't found by luck of recency.
    DataRecord::factory()->count(60)->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'Total',
        'data' => ['unrelated', 'filler'],
    ]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'Defects:'."\n".'- قطع → Count: 1'], 200)]);

    $this->postJson('/api/defects/area-scrap-count', [
        'source_id' => $source->id,
        'message' => 'what are the defects on 21/7/2026?',
    ])->assertOk();

    Http::assertSent(fn ($request) => str_contains($request['prompt'], 'قطع')
        && ! str_contains($request['prompt'], 'unrelated'));
});

test('it only searches the Total sheet, not other sheets in the same source even if they match', function () {
    $source = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);

    $serial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 7, 21));

    // Matches the date, but lives on a DIFFERENT sheet whose columns
    // don't mean the same thing under the fixed mapping.
    DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'Chart',
        'data' => [$serial, 'not really area/defect data'],
    ]);

    // The Total sheet must exist for the request to be accepted at all.
    DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'Total',
        'data' => ['unrelated', 'filler'],
    ]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'No defects found'], 200)]);

    // The only date match lives on the "Chart" sheet, which doesn't
    // count for this endpoint's Total-sheet-only scope - a confirmed
    // negative determined entirely by SQL, so the AI is never even
    // called (see AreaScrapDefectCountController's docblock for why:
    // this is the actual fix for "says no data" happening even when a
    // prompt insists otherwise - the decision is made in PHP, not asked
    // of the model).
    $this->postJson('/api/defects/area-scrap-count', [
        'source_id' => $source->id,
        'message' => 'what are the defects on 21/7/2026?',
    ])
        ->assertOk()
        ->assertJson(['answer' => 'No defects found for this date']);

    Http::assertNothingSent();
});

test('it excludes another source\'s matching rows, even in that source\'s own Total sheet', function () {
    $sourceA = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);
    $sourceB = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);

    $serial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 7, 21));

    DataRecord::factory()->create([
        'source_id' => $sourceA->id,
        'department' => 'quality',
        'sheet_name' => 'Total',
        'data' => ['from source A'],
    ]);

    DataRecord::factory()->create([
        'source_id' => $sourceB->id,
        'department' => 'quality',
        'sheet_name' => 'Total',
        'data' => [$serial, 'from source B'],
    ]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    // Source A's own Total sheet has no date match at all - a confirmed
    // negative for source A specifically, so the AI is never called
    // (source B's match is irrelevant, source A's own data is what's
    // being asked about).
    $this->postJson('/api/defects/area-scrap-count', [
        'source_id' => $sourceA->id,
        'message' => 'what are the defects on 21/7/2026?',
    ])
        ->assertOk()
        ->assertJson(['answer' => 'No defects found for this date']);

    Http::assertNothingSent();
});

test('it falls back to a natural-order Total-sheet sample when the question has no date or keyword signal', function () {
    $source = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);
    DataRecord::factory()->count(5)->create(['source_id' => $source->id, 'department' => 'quality', 'sheet_name' => 'Total']);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/defects/area-scrap-count', ['source_id' => $source->id, 'message' => 'ok'])
        ->assertOk();
});

test('it returns the AI\'s reply wrapped in an "answer" string', function () {
    $source = Source::factory()->create();
    DataRecord::factory()->create(['source_id' => $source->id, 'sheet_name' => 'Total']);

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => "Defects:\n- قطع → Count: 1"], 200),
    ]);

    $this->postJson('/api/defects/area-scrap-count', ['source_id' => $source->id, 'message' => 'ok'])
        ->assertOk()
        ->assertJson(['answer' => "Defects:\n- قطع → Count: 1"]);
});

test('it does not request Ollama\'s JSON format constraint', function () {
    $source = Source::factory()->create();
    DataRecord::factory()->create(['source_id' => $source->id, 'sheet_name' => 'Total']);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/defects/area-scrap-count', ['source_id' => $source->id, 'message' => 'ok'])->assertOk();

    Http::assertSent(fn ($request) => ! array_key_exists('format', $request->data()));
});

test('it returns 503 when Ollama is unavailable', function () {
    $source = Source::factory()->create();
    DataRecord::factory()->create(['source_id' => $source->id, 'sheet_name' => 'Total']);

    Http::fake(['ollama.test/*' => Http::response('Service Unavailable', 500)]);

    $this->postJson('/api/defects/area-scrap-count', ['source_id' => $source->id, 'message' => 'ok'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

test('source_id is required', function () {
    $this->postJson('/api/defects/area-scrap-count', ['message' => 'ok'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_id');
});

test('source_id must reference an existing source', function () {
    $this->postJson('/api/defects/area-scrap-count', ['source_id' => 999999, 'message' => 'ok'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_id');
});

test('message is required', function () {
    $source = Source::factory()->create();
    DataRecord::factory()->create(['source_id' => $source->id, 'sheet_name' => 'Total']);

    $this->postJson('/api/defects/area-scrap-count', ['source_id' => $source->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');
});
