<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Models\Department;
use App\Models\Source;
use Illuminate\Support\Facades\Http;

function makeDefectDataRow(string $departmentSlug, array $overrides = []): DataRecord
{
    $source = Source::factory()->create([
        'department_id' => Department::where('slug', $departmentSlug)->value('id'),
    ]);

    return DataRecord::factory()->create(array_merge([
        'source_id' => $source->id,
        'department' => $departmentSlug,
    ], $overrides));
}

test('it returns the AI\'s parsed JSON directly, not wrapped in an "answer" string', function () {
    makeDefectDataRow('quality', ['data' => ['Contamination', 12, '5%']]);

    $schema = [
        'detected_tables' => ['Table1'],
        'defects' => [['name' => 'Contamination', 'count' => 12, 'percentage' => 5]],
        'ignored_rows_reason' => 'none',
        'confidence' => 'high',
    ];

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => json_encode($schema)], 200),
    ]);

    $this->postJson('/api/defects/analyze', ['department' => 'quality'])
        ->assertOk()
        ->assertJson($schema);
});

test('it strips a markdown code fence around the AI\'s JSON reply', function () {
    makeDefectDataRow('quality');

    Http::fake([
        'ollama.test/api/generate' => Http::response([
            'response' => "```json\n".json_encode(['confidence' => 'low', 'defects' => [], 'detected_tables' => [], 'ignored_rows_reason' => 'none'])."\n```",
        ], 200),
    ]);

    $this->postJson('/api/defects/analyze', ['department' => 'quality'])
        ->assertOk()
        ->assertJsonPath('confidence', 'low');
});

test('it requests Ollama\'s native JSON format constraint', function () {
    makeDefectDataRow('quality');

    Http::fake(['ollama.test/*' => Http::response(['response' => '{}'], 200)]);

    $this->postJson('/api/defects/analyze', ['department' => 'quality'])->assertOk();

    Http::assertSent(fn ($request) => $request['format'] === 'json');
});

test('it only includes the requested department\'s rows in the prompt', function () {
    makeDefectDataRow('quality', ['data' => ['Contamination', 11.11]]);
    makeDefectDataRow('it', ['data' => ['Bad Adhesion', 22.22]]);

    Http::fake(['ollama.test/*' => Http::response(['response' => '{}'], 200)]);

    $this->postJson('/api/defects/analyze', ['department' => 'it'])->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request['prompt'], '22.22')
            && ! str_contains($request['prompt'], '11.11');
    });
});

test('it returns 502 when the AI reply is not valid JSON', function () {
    makeDefectDataRow('quality');

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => 'Sure, here is the analysis: not actually JSON'], 200),
    ]);

    $this->postJson('/api/defects/analyze', ['department' => 'quality'])
        ->assertStatus(502)
        ->assertJsonStructure(['error']);
});

test('it returns 503 when Ollama is unavailable', function () {
    Http::fake(['ollama.test/*' => Http::response('Service Unavailable', 500)]);

    $this->postJson('/api/defects/analyze', ['department' => 'quality'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

test('department must be a known department', function () {
    $this->postJson('/api/defects/analyze', ['department' => 'finance'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});

test('department is required', function () {
    $this->postJson('/api/defects/analyze', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});
