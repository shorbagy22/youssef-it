<?php

declare(strict_types=1);

use App\Models\DataRecord;
use Illuminate\Support\Facades\Http;

test('it answers using the department\'s recent data records', function () {
    DataRecord::factory()->create([
        'department' => 'quality',
        'date' => '2026-05-01',
        'nrft' => 95.5,
        'ppm' => 120,
        'defects' => ['scratch'],
    ]);

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => 'NRFT was 95.50 in May.'], 200),
    ]);

    $this->postJson('/api/chat', [
        'department' => 'quality',
        'message' => 'What is NRFT in May?',
    ])
        ->assertOk()
        ->assertJson(['answer' => 'NRFT was 95.50 in May.']);

    Http::assertSent(fn ($request) => str_contains($request['prompt'], '95.50')
        && str_contains($request['prompt'], 'What is NRFT in May?'));
});

test('it only includes the requested department\'s records', function () {
    DataRecord::factory()->create(['department' => 'quality', 'date' => '2026-05-01', 'nrft' => 11.11]);
    DataRecord::factory()->create(['department' => 'it', 'date' => '2026-05-01', 'nrft' => 22.22]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/chat', ['department' => 'it', 'message' => 'Status?'])
        ->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request['prompt'], 'NRFT: 22.22')
            && ! str_contains($request['prompt'], 'NRFT: 11.11');
    });
});

test('it returns 503 when Ollama is unavailable', function () {
    Http::fake(['ollama.test/*' => Http::response('Service Unavailable', 500)]);

    $this->postJson('/api/chat', ['department' => 'quality', 'message' => 'Hello'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

test('department must be one of the known values', function () {
    $this->postJson('/api/chat', ['department' => 'finance', 'message' => 'Hello'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});

test('message is required', function () {
    $this->postJson('/api/chat', ['department' => 'quality'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');
});
