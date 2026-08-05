<?php

declare(strict_types=1);

use App\Exceptions\OllamaUnavailableException;
use App\Services\OllamaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('generate returns the response text on success', function () {
    Http::fake([
        'localhost:11434/api/generate' => Http::response(['response' => 'Hello there.'], 200),
    ]);

    $client = new OllamaClient;

    expect($client->generate('system prompt', 'user prompt'))->toBe('Hello there.');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://localhost:11434/api/generate'
            && $request['model'] === 'llama3.1'
            && $request['system'] === 'system prompt'
            && $request['prompt'] === 'user prompt'
            && $request['stream'] === false;
    });
});

test('generate throws when Ollama responds with a failure status', function () {
    Http::fake([
        'localhost:11434/api/generate' => Http::response('Internal Server Error', 500),
    ]);

    $client = new OllamaClient;

    expect(fn () => $client->generate('system', 'user'))
        ->toThrow(OllamaUnavailableException::class);
});

test('generate throws when the connection to Ollama fails', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $client = new OllamaClient;

    expect(fn () => $client->generate('system', 'user'))
        ->toThrow(OllamaUnavailableException::class);
});

test('generate throws when the response is missing a string "response" field', function () {
    Http::fake([
        'localhost:11434/api/generate' => Http::response(['done' => true], 200),
    ]);

    $client = new OllamaClient;

    expect(fn () => $client->generate('system', 'user'))
        ->toThrow(OllamaUnavailableException::class);
});

test('isHealthy returns true when the server responds successfully', function () {
    Http::fake([
        'localhost:11434/' => Http::response('Ollama is running', 200),
    ]);

    $client = new OllamaClient;

    expect($client->isHealthy())->toBeTrue();
});

test('isHealthy returns false when Ollama responds with a failure status', function () {
    Http::fake([
        'localhost:11434/' => Http::response('', 500),
    ]);

    $client = new OllamaClient;

    expect($client->isHealthy())->toBeFalse();
});

test('isHealthy returns false when the connection fails', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $client = new OllamaClient;

    expect($client->isHealthy())->toBeFalse();
});
