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
            && $request['model'] === 'qwen3.5:9b'
            && $request['prompt'] === 'a prompt'
            && $request['stream'] === false
            && $request['think'] === false;
    });
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

test('generate throws when Ollama returns a blank response', function () {
    Http::fake([
        'ollama.test/api/generate' => Http::response(['response' => '   '], 200),
    ]);

    expect(fn () => (new OllamaClient)->generate('a prompt'))
        ->toThrow(AIServiceUnavailableException::class);
});
