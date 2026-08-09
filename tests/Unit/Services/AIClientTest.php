<?php

declare(strict_types=1);

use App\Exceptions\AIServiceUnavailableException;
use App\Services\AIClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('generate posts the question and system prompt and returns the answer', function () {
    Http::fake([
        'ai-service.test/*' => Http::response(['answer' => 'Hello there.'], 200),
    ]);

    $client = new AIClient;

    expect($client->generate('system prompt', 'user question'))->toBe('Hello there.');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/'
            && $request['question'] === 'user question'
            && $request['system'] === 'system prompt';
    });
});

test('generate throws when the AI service responds with a failure status', function () {
    Http::fake([
        'ai-service.test/*' => Http::response('Internal Server Error', 500),
    ]);

    $client = new AIClient;

    expect(fn () => $client->generate('system', 'question'))
        ->toThrow(AIServiceUnavailableException::class);
});

test('generate throws when the connection to the AI service fails', function () {
    Http::fake([
        'ai-service.test/*' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    $client = new AIClient;

    expect(fn () => $client->generate('system', 'question'))
        ->toThrow(AIServiceUnavailableException::class);
});

test('generate throws when the response is missing a string "answer" field', function () {
    Http::fake([
        'ai-service.test/*' => Http::response(['status' => 'ok'], 200),
    ]);

    $client = new AIClient;

    expect(fn () => $client->generate('system', 'question'))
        ->toThrow(AIServiceUnavailableException::class);
});

test('isHealthy returns true when the health endpoint responds successfully', function () {
    Http::fake([
        'ai-service.test/health' => Http::response('OK', 200),
    ]);

    $client = new AIClient;

    expect($client->isHealthy())->toBeTrue();
});

test('isHealthy returns false when the health endpoint responds with a failure status', function () {
    Http::fake([
        'ai-service.test/health' => Http::response('Not Found', 404),
    ]);

    $client = new AIClient;

    expect($client->isHealthy())->toBeFalse();
});

test('isHealthy returns false when the connection fails', function () {
    Http::fake([
        'ai-service.test/*' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    $client = new AIClient;

    expect($client->isHealthy())->toBeFalse();
});
