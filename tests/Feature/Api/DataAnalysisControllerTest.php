<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Models\Department;
use App\Models\Source;
use Illuminate\Support\Facades\Http;

function makeAnalysisDataRow(string $departmentSlug, array $overrides = []): DataRecord
{
    $source = Source::factory()->create([
        'department_id' => Department::where('slug', $departmentSlug)->value('id'),
    ]);

    return DataRecord::factory()->create(array_merge([
        'source_id' => $source->id,
        'department' => $departmentSlug,
    ], $overrides));
}

test('it returns the AI\'s parsed "corrupted" JSON directly, not wrapped in an "answer" string', function () {
    makeAnalysisDataRow('quality', ['data' => ['line' => 1, 'text' => 'رادصلًا خيرات 202409--01']]);

    $schema = [
        'status' => 'corrupted',
        'reason' => 'Data appears unreadable or incorrectly extracted from source (likely PDF extraction issue).',
        'examples' => ['رادصلًا خيرات 202409--01'],
    ];

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => json_encode($schema)], 200),
    ]);

    $this->postJson('/api/data/analyze', ['department' => 'quality'])
        ->assertOk()
        ->assertJson($schema);
});

test('it returns the AI\'s parsed "ok" JSON directly when data is readable', function () {
    makeAnalysisDataRow('quality', ['data' => ['Contamination', 12]]);

    $schema = [
        'status' => 'ok',
        'analysis' => [
            'detected_structure' => 'single table',
            'columns_detected' => ['Defect', 'Count'],
            'reliable_rows_sample' => [['Contamination', 12]],
            'insights' => [],
            'limitations' => [],
        ],
    ];

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => json_encode($schema)], 200),
    ]);

    $this->postJson('/api/data/analyze', ['department' => 'quality'])
        ->assertOk()
        ->assertJson($schema);
});

test('it requests Ollama\'s native JSON format constraint', function () {
    makeAnalysisDataRow('quality');

    Http::fake(['ollama.test/*' => Http::response(['response' => '{}'], 200)]);

    $this->postJson('/api/data/analyze', ['department' => 'quality'])->assertOk();

    Http::assertSent(fn ($request) => $request['format'] === 'json');
});

test('it includes the question in the prompt when message is given', function () {
    makeAnalysisDataRow('quality');

    Http::fake(['ollama.test/*' => Http::response(['response' => '{}'], 200)]);

    $this->postJson('/api/data/analyze', ['department' => 'quality', 'message' => 'What is the total defect count?'])->assertOk();

    Http::assertSent(fn ($request) => str_contains($request['prompt'], 'What is the total defect count?'));
});

test('it only includes the requested department\'s rows when no source_id is given', function () {
    makeAnalysisDataRow('quality', ['data' => ['line' => 1, 'text' => '11.11']]);
    makeAnalysisDataRow('it', ['data' => ['line' => 1, 'text' => '22.22']]);

    Http::fake(['ollama.test/*' => Http::response(['response' => '{}'], 200)]);

    $this->postJson('/api/data/analyze', ['department' => 'it'])->assertOk();

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

    Http::fake(['ollama.test/*' => Http::response(['response' => '{}'], 200)]);

    $this->postJson('/api/data/analyze', ['department' => 'quality', 'source_id' => $sourceA->id])->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request['prompt'], 'from source A')
            && ! str_contains($request['prompt'], 'from source B');
    });
});

test('it rejects a source_id that belongs to a different department than the one given', function () {
    $qualitySource = Source::factory()->create(['department_id' => Department::where('slug', 'quality')->value('id')]);

    $this->postJson('/api/data/analyze', [
        'department' => 'it',
        'source_id' => $qualitySource->id,
    ])->assertStatus(422);
});

test('it returns 502 when the AI reply is not valid JSON', function () {
    makeAnalysisDataRow('quality');

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => 'Sure, here is the analysis: not actually JSON'], 200),
    ]);

    $this->postJson('/api/data/analyze', ['department' => 'quality'])
        ->assertStatus(502)
        ->assertJsonStructure(['error']);
});

test('it returns 503 when Ollama is unavailable', function () {
    Http::fake(['ollama.test/*' => Http::response('Service Unavailable', 500)]);

    $this->postJson('/api/data/analyze', ['department' => 'quality'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

test('department must be a known department', function () {
    $this->postJson('/api/data/analyze', ['department' => 'finance'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});

test('department is required', function () {
    $this->postJson('/api/data/analyze', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});

test('source_id must reference an existing source if given', function () {
    $this->postJson('/api/data/analyze', ['department' => 'quality', 'source_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_id');
});
