<?php

declare(strict_types=1);

use App\Contracts\LLMClient;
use App\DTOs\ChatRequest;
use App\Exceptions\OllamaUnavailableException;
use App\Services\ChatService;
use App\Services\PromptBuilder;

test('it builds prompts via PromptBuilder and returns the model answer', function () {
    $llmClient = Mockery::mock(LLMClient::class);
    $llmClient->shouldReceive('generate')
        ->once()
        ->with(Mockery::type('string'), "User: Hi\nUser: What's up?")
        ->andReturn('All good!');

    $service = new ChatService($llmClient, new PromptBuilder);

    $response = $service->handle(new ChatRequest(
        message: "What's up?",
        history: [['role' => 'user', 'content' => 'Hi']],
    ));

    expect($response->answer)->toBe('All good!');
});

test('it lets OllamaUnavailableException from the LLM client bubble up', function () {
    $llmClient = Mockery::mock(LLMClient::class);
    $llmClient->shouldReceive('generate')
        ->once()
        ->andThrow(new OllamaUnavailableException('down'));

    $service = new ChatService($llmClient, new PromptBuilder);

    expect(fn () => $service->handle(new ChatRequest(message: 'Hi')))
        ->toThrow(OllamaUnavailableException::class);
});
