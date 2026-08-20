<?php

declare(strict_types=1);

use App\Exceptions\AIServiceUnavailableException;
use App\Services\OllamaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('generate posts the prompt and returns Ollama\'s "response" field', function () {
    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => 'Hello there.'], 200),
    ]);

    $client = new OllamaClient;

    expect($client->generate('a prompt'))->toBe('Hello there.');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ollama.test/api/generate'
            && $request['model'] === 'qwen2.5:9b'
            && $request['prompt'] === 'a prompt'
            && $request['stream'] === false;
    });
});

test('generate requests a generous context window from Ollama, not the model\'s small default - regression test for a real, confirmed bug', function () {
    // A real PDF Q&A request only ever "saw" the last ~25 lines of a
    // 251-line document, because nothing told Ollama how large a
    // context window to use - it fell back to the model's own small
    // default (often 2048-4096 tokens) and silently dropped the OLDEST
    // tokens once the document's full text exceeded it, cutting off
    // content near the START of the document instead of erroring.
    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => 'Hello there.'], 200),
    ]);

    (new OllamaClient)->generate('a prompt');

    Http::assertSent(fn ($request) => $request['options']['num_ctx'] === config('ollama.context_window')
        && $request['options']['num_ctx'] >= 8192);
});

test('generate omits the format field by default', function () {
    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => 'Hello there.'], 200),
    ]);

    (new OllamaClient)->generate('a prompt');

    Http::assertSent(fn ($request) => ! array_key_exists('format', $request->data()));
});

test('generate sets Ollama\'s own JSON format constraint when jsonMode is true', function () {
    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => '{"ok":true}'], 200),
    ]);

    (new OllamaClient)->generate('a prompt', jsonMode: true);

    Http::assertSent(fn ($request) => $request['format'] === 'json');
});

test('generate throws when Ollama responds with a failure status', function () {
    Http::fake([
        'ollama.test/api/generate' => Http::response('Internal Server Error', 500),
    ]);

    $client = new OllamaClient;

    expect(fn () => $client->generate('a prompt'))
        ->toThrow(AIServiceUnavailableException::class);
});

test('generate throws when the connection to Ollama fails', function () {
    Http::fake([
        'ollama.test/*' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    $client = new OllamaClient;

    expect(fn () => $client->generate('a prompt'))
        ->toThrow(AIServiceUnavailableException::class);
});

test('generate throws when the response is missing a string "response" field', function () {
    Http::fake([
        'ollama.test/api/generate' => Http::response(['done' => true], 200),
    ]);

    $client = new OllamaClient;

    expect(fn () => $client->generate('a prompt'))
        ->toThrow(AIServiceUnavailableException::class);
});
