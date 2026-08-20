<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Models\Department;
use App\Models\Source;
use Illuminate\Support\Facades\Http;

function makeReadabilityDataRow(string $departmentSlug, array $overrides = []): DataRecord
{
    $source = Source::factory()->create([
        'department_id' => Department::where('slug', $departmentSlug)->value('id'),
    ]);

    return DataRecord::factory()->create(array_merge([
        'source_id' => $source->id,
        'department' => $departmentSlug,
    ], $overrides));
}

test('it returns the AI\'s reply wrapped in an "answer" string, unlike the defect endpoint\'s raw passthrough', function () {
    makeReadabilityDataRow('quality', ['data' => ['line' => 1, 'text' => 'Total defects: 12']]);

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => 'No clear structure detected.'], 200),
    ]);

    $this->postJson('/api/data/check-readability', ['department' => 'quality'])
        ->assertOk()
        ->assertJson(['answer' => 'No clear structure detected.']);
});

test('it does not request Ollama\'s JSON format constraint - this endpoint has no fixed schema', function () {
    makeReadabilityDataRow('quality');

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/data/check-readability', ['department' => 'quality'])->assertOk();

    Http::assertSent(fn ($request) => ! array_key_exists('format', $request->data()));
});

test('it only includes the requested department\'s rows when no source_id is given', function () {
    makeReadabilityDataRow('quality', ['data' => ['line' => 1, 'text' => '11.11']]);
    makeReadabilityDataRow('it', ['data' => ['line' => 1, 'text' => '22.22']]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/data/check-readability', ['department' => 'it'])->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request['prompt'], '22.22')
            && ! str_contains($request['prompt'], '11.11');
    });
});

test('it narrows to just the given source when source_id is provided', function () {
    $sourceA = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);
    $sourceB = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);

    DataRecord::factory()->create(['source_id' => $sourceA->id, 'department' => 'quality', 'data' => ['line' => 1, 'text' => 'from source A']]);
    DataRecord::factory()->create(['source_id' => $sourceB->id, 'department' => 'quality', 'data' => ['line' => 1, 'text' => 'from source B']]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/data/check-readability', ['department' => 'quality', 'source_id' => $sourceA->id])->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request['prompt'], 'from source A')
            && ! str_contains($request['prompt'], 'from source B');
    });
});

test('it rejects a source_id that belongs to a different department than the one given', function () {
    $qualitySource = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);

    $this->postJson('/api/data/check-readability', [
        'department' => 'it',
        'source_id' => $qualitySource->id,
    ])->assertStatus(422);
});

test('it returns 503 when Ollama is unavailable', function () {
    Http::fake(['ollama.test/*' => Http::response('Service Unavailable', 500)]);

    $this->postJson('/api/data/check-readability', ['department' => 'quality'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

test('department must be a known department', function () {
    $this->postJson('/api/data/check-readability', ['department' => 'finance'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});

test('department is required', function () {
    $this->postJson('/api/data/check-readability', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});

test('source_id must reference an existing source if given', function () {
    $this->postJson('/api/data/check-readability', ['department' => 'quality', 'source_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_id');
});
