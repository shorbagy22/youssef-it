<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Models\Department;
use App\Models\Source;
use Illuminate\Support\Facades\Http;

test('it finds the matching date row via SQL search, regardless of recency - reuses the proven ChatDataService fix', function () {
    $source = Source::factory()->create();

    $serial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 7, 21));

    // The real match - created FIRST, so it has a LOWER id than every
    // decoy below, proving this isn't found by luck of recency.
    $target = DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'data' => [$serial, ' Assembly', 'قطع', 4],
    ]);

    DataRecord::factory()->count(60)->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'data' => ['unrelated', 'filler'],
    ]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'Defect | Total Quantity'], 200)]);

    $this->postJson('/api/defects/query', [
        'department' => 'quality',
        'message' => 'what are the defects in assembly area on 21/7/2026?',
    ])->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request['prompt'], 'قطع')
            && ! str_contains($request['prompt'], 'unrelated');
    });
});

test('it tells the AI a match is CONFIRMED when the SQL search found one, instead of leaving it to decide', function () {
    $source = Source::factory()->create();

    $serial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 7, 21));

    DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'data' => [$serial, ' Assembly', 'قطع', 4],
    ]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/defects/query', [
        'department' => 'quality',
        'message' => 'what are the defects in assembly area on 21/7/2026?',
    ])->assertOk();

    Http::assertSent(fn ($request) => str_contains($request['prompt'], 'BACKEND CONFIRMATION')
        && str_contains($request['prompt'], 'This is a FACT, not something to verify'));
});

test('a keyword match (e.g. "assembly", present on many unrelated dates) is NEVER treated as confirmation for a specific date question - regression test for a real, live-confirmed bug', function () {
    // Rows in "Assembly" area, but on a DIFFERENT date than the one
    // asked about - if a keyword match on "assembly" were wrongly
    // treated as confirming the question's specific date, the AI would
    // be told these ARE the confirmed rows and would confidently
    // report May/June data as if it answered a June 25th question -
    // exactly what was observed live against this app's real data.
    $otherDateSerial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 5, 1));

    DataRecord::factory()->count(10)->create([
        'department' => 'quality',
        'data' => [$otherDateSerial, ' Assembly', 'قطع', 4],
    ]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'this must never be seen'], 200)]);

    $this->postJson('/api/defects/query', [
        'department' => 'quality',
        'message' => 'what are the defects on 25/6/2026 in assembly?',
    ])
        ->assertOk()
        ->assertJson(['answer' => 'No defects found for the specified date and area.']);

    // The decisive assertion: the AI must never even be called - if a
    // keyword match were mistakenly treated as a date confirmation, an
    // Ollama request would have been sent with those May rows in it.
    Http::assertNothingSent();
});

test('it answers "no defects found" directly, without calling the AI, when a date search confirms zero matches - the actual root fix for a real "says no data" bug', function () {
    // A date IS present in the question (a searchable signal), but
    // nothing in the department matches it - SQL has already determined
    // this with certainty, so there's nothing left for the AI to
    // decide. This is the fix for the repeatedly-observed bug where the
    // AI would sometimes say "no data" even when real matching rows
    // existed elsewhere, and - just as importantly - the reverse: an AI
    // asked to "decide" could just as easily hallucinate a match that
    // was never really there. Removing the decision from the AI
    // entirely fixes both directions at once.
    DataRecord::factory()->create([
        'department' => 'quality',
        'data' => ['completely unrelated', 'data'],
    ]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'this should never be seen'], 200)]);

    $this->postJson('/api/defects/query', [
        'department' => 'quality',
        'message' => 'what are the defects in assembly area on 21/7/2026?',
    ])
        ->assertOk()
        ->assertJson(['answer' => 'No defects found for the specified date and area.']);

    Http::assertNothingSent();
});

test('it falls back to a natural-order sample when the question has no date or keyword signal', function () {
    $source = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);
    DataRecord::factory()->count(5)->create(['source_id' => $source->id, 'department' => 'quality']);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/defects/query', ['department' => 'quality', 'message' => 'ok'])
        ->assertOk();
});

test('it returns the AI\'s reply wrapped in an "answer" string', function () {
    DataRecord::factory()->create(['department' => 'quality']);

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => "Total defects: 4\nDefect | Total Quantity\nقطع | 4"], 200),
    ]);

    $this->postJson('/api/defects/query', ['department' => 'quality', 'message' => 'ok'])
        ->assertOk()
        ->assertJson(['answer' => "Total defects: 4\nDefect | Total Quantity\nقطع | 4"]);
});

test('it does not request Ollama\'s JSON format constraint - the output is a formatted text table', function () {
    DataRecord::factory()->create(['department' => 'quality']);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/defects/query', ['department' => 'quality', 'message' => 'ok'])->assertOk();

    Http::assertSent(fn ($request) => ! array_key_exists('format', $request->data()));
});

test('it returns 503 when Ollama is unavailable', function () {
    Http::fake(['ollama.test/*' => Http::response('Service Unavailable', 500)]);

    $this->postJson('/api/defects/query', ['department' => 'quality', 'message' => 'ok'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

test('department must be a known department', function () {
    $this->postJson('/api/defects/query', ['department' => 'finance', 'message' => 'ok'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});

test('message is required', function () {
    $this->postJson('/api/defects/query', ['department' => 'quality'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');
});
