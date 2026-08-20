<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Models\Department;
use App\Models\Source;
use Illuminate\Support\Facades\Http;

function makeDataRow(string $departmentSlug, array $overrides = []): DataRecord
{
    $source = Source::factory()->create([
        'department_id' => Department::where('slug', $departmentSlug)->value('id'),
    ]);

    return DataRecord::factory()->create(array_merge([
        'source_id' => $source->id,
        'department' => $departmentSlug,
    ], $overrides));
}

test('it answers using the department\'s synced rows', function () {
    makeDataRow('quality', ['data' => ['2026-05-01', 95.5]]);

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => 'NRFT was 95.50 in May.'], 200),
    ]);

    $this->postJson('/api/chat', [
        'department' => 'quality',
        'message' => 'What is NRFT in May?',
    ])
        ->assertOk()
        ->assertJson(['answer' => 'NRFT was 95.50 in May.']);

    Http::assertSent(fn ($request) => str_contains($request['prompt'], '95.5')
        && str_contains($request['prompt'], 'What is NRFT in May?'));
});

test('it only includes the requested department\'s rows', function () {
    makeDataRow('quality', ['data' => [11.11]]);
    makeDataRow('it', ['data' => [22.22]]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/chat', ['department' => 'it', 'message' => 'Status?'])
        ->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request['prompt'], '22.22')
            && ! str_contains($request['prompt'], '11.11');
    });
});

test('it caps how many rows go into the prompt', function () {
    $source = Source::factory()->create([
        'department_id' => Department::where('slug', 'quality')->value('id'),
    ]);

    // One more than ChatController::ROW_LIMIT (500).
    DataRecord::factory()->count(501)->create(['source_id' => $source->id, 'department' => 'quality']);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/chat', ['department' => 'quality', 'message' => 'Status?'])->assertOk();

    Http::assertSent(function ($request) {
        $data = substr($request['prompt'], strpos($request['prompt'], "DATA:\n") + strlen("DATA:\n"));
        $data = substr($data, 0, strpos($data, "\n\nQUESTION:"));
        $decoded = json_decode($data, true);

        return count($decoded['datasets'][0]['rows']) <= 500;
    });
});

test('it returns the raw matching rows directly for a confirmed date match, without calling the AI at all', function () {
    // Verbatim extraction, not AI summarization/transcription - the
    // fix for a real, live-confirmed problem: even WITH a "this is
    // confirmed" instruction, the AI would still sometimes paraphrase a
    // defect name or garble which column meant what. When PHP already
    // knows the exact matching rows with certainty, the raw data IS the
    // answer.
    $source = Source::factory()->create([
        'name' => 'area scrap',
        'department_id' => Department::where('slug', 'quality')->value('id'),
    ]);
    $serial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 7, 21));
    DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'Total',
        'data' => [$serial, ' Assembly', 'قطع', 4],
    ]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'this must never be seen'], 200)]);

    $this->postJson('/api/chat', ['department' => 'quality', 'message' => 'what happened on 21/7/26?'])
        ->assertOk()
        ->assertJson(['answer' => "=== area scrap / Total ===\n\n21/07/26\n Assembly\nقطع\n4"]);

    Http::assertNothingSent();
});

test('it answers with a fixed "no data" message directly, without calling the AI, when a date search confirms zero matches - regression test for a real, live-confirmed bug', function () {
    // A date IS present in the question, and completely unrelated data
    // exists in the department - if a keyword match on "assembly" (or
    // any other broad term) were wrongly treated as confirming this
    // specific date, the AI would be shown unrelated rows and could
    // confidently report them as if they answered this date's question.
    makeDataRow('quality', ['data' => ['completely unrelated', ' Assembly', 'قطع', 4]]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'this must never be seen'], 200)]);

    $this->postJson('/api/chat', ['department' => 'quality', 'message' => 'what happened on 25/6/26?'])
        ->assertOk()
        ->assertJson(['answer' => 'No data found for that specific date in the provided records.']);

    Http::assertNothingSent();
});

test('an ambiguous date like 3/6/26 in live chat resolves as day-first (June 3), not month-first (March 6)', function () {
    $source = Source::factory()->create([
        'name' => 'area scrap',
        'department_id' => Department::where('slug', 'quality')->value('id'),
    ]);
    $juneThirdSerial = (int) Carbon\Carbon::create(1899, 12, 30)->diffInDays(Carbon\Carbon::create(2026, 6, 3));

    DataRecord::factory()->create([
        'source_id' => $source->id,
        'department' => 'quality',
        'sheet_name' => 'Total',
        'data' => [$juneThirdSerial, ' Assembly', 'كسر', 2],
    ]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'this must never be seen'], 200)]);

    $this->postJson('/api/chat', ['department' => 'quality', 'message' => 'defects on 3/6/26 in assembly'])
        ->assertOk()
        ->assertJson(['answer' => "=== area scrap / Total ===\n\n03/06/26\n Assembly\nكسر\n2"]);

    Http::assertNothingSent();
});

test('it returns 503 when Ollama is unavailable', function () {
    Http::fake(['ollama.test/*' => Http::response('Service Unavailable', 500)]);

    $this->postJson('/api/chat', ['department' => 'quality', 'message' => 'Hello'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

test('department must be a known department', function () {
    $this->postJson('/api/chat', ['department' => 'finance', 'message' => 'Hello'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});

test('message is required', function () {
    $this->postJson('/api/chat', ['department' => 'quality'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');
});
