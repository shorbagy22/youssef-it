<?php

declare(strict_types=1);

use App\Models\DataRecord;
use App\Models\Source;
use Illuminate\Support\Facades\Http;

test('it returns the AI\'s exact-extraction reply wrapped in an "answer" string', function () {
    $source = Source::factory()->create(['file_path' => 'C:\\data\\report.pdf']);
    DataRecord::factory()->create(['source_id' => $source->id, 'sheet_name' => 'pdf', 'row_index' => 1, 'data' => ['line' => 1, 'text' => 'منظمة أو شخص']]);

    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => 'منظمة أو شخص يمكن أن يؤثر'], 200),
    ]);

    $this->postJson('/api/pdf/ask', ['source_id' => $source->id, 'message' => 'ما الجهة المعنية؟'])
        ->assertOk()
        ->assertJson(['answer' => 'منظمة أو شخص يمكن أن يؤثر']);
});

test('it does not request Ollama\'s JSON format constraint - the output is plain extracted text', function () {
    $source = Source::factory()->create(['file_path' => 'C:\\data\\report.pdf']);
    DataRecord::factory()->create(['source_id' => $source->id]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/pdf/ask', ['source_id' => $source->id, 'message' => 'question?'])->assertOk();

    Http::assertSent(fn ($request) => ! array_key_exists('format', $request->data()));
});

test('it only includes the given source\'s rows, not any other source\'s', function () {
    $pdfSource = Source::factory()->create(['file_path' => 'C:\\data\\report.pdf']);
    $otherSource = Source::factory()->create(['file_path' => 'C:\\data\\other.pdf']);

    DataRecord::factory()->create(['source_id' => $pdfSource->id, 'data' => ['line' => 1, 'text' => 'from this document']]);
    DataRecord::factory()->create(['source_id' => $otherSource->id, 'data' => ['line' => 1, 'text' => 'from a different document']]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/pdf/ask', ['source_id' => $pdfSource->id, 'message' => 'question?'])->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request['prompt'], 'from this document')
            && ! str_contains($request['prompt'], 'from a different document');
    });
});

test('it accepts a url-type PDF source, detected by the URL\'s own extension', function () {
    $source = Source::factory()->url()->create(['url' => 'https://example.test/files/policy.pdf']);
    DataRecord::factory()->create(['source_id' => $source->id]);

    Http::fake(['ollama.test/*' => Http::response(['response' => 'ok'], 200)]);

    $this->postJson('/api/pdf/ask', ['source_id' => $source->id, 'message' => 'question?'])->assertOk();
});

test('it rejects a source_id that is not a PDF source', function () {
    $excelSource = Source::factory()->create(['file_path' => 'C:\\data\\report.xlsx']);
    DataRecord::factory()->create(['source_id' => $excelSource->id]);

    $this->postJson('/api/pdf/ask', ['source_id' => $excelSource->id, 'message' => 'question?'])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});

test('it returns 503 when Ollama is unavailable', function () {
    $source = Source::factory()->create(['file_path' => 'C:\\data\\report.pdf']);
    DataRecord::factory()->create(['source_id' => $source->id]);

    Http::fake(['ollama.test/*' => Http::response('Service Unavailable', 500)]);

    $this->postJson('/api/pdf/ask', ['source_id' => $source->id, 'message' => 'question?'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

test('source_id is required', function () {
    $this->postJson('/api/pdf/ask', ['message' => 'question?'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_id');
});

test('source_id must reference an existing source', function () {
    $this->postJson('/api/pdf/ask', ['source_id' => 999999, 'message' => 'question?'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_id');
});

test('message (the question) is required', function () {
    $source = Source::factory()->create(['file_path' => 'C:\\data\\report.pdf']);

    $this->postJson('/api/pdf/ask', ['source_id' => $source->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');
});
