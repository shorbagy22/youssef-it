<?php

declare(strict_types=1);

use App\Actions\ChatAction;
use App\Contracts\LLMClient;
use App\DTOs\ChatRequest;
use App\Services\ChatService;
use App\Services\PromptBuilder;

test('it delegates to ChatService', function () {
    $llmClient = Mockery::mock(LLMClient::class);
    $llmClient->shouldReceive('generate')->once()->andReturn('Hello!');

    $chatService = new ChatService($llmClient, new PromptBuilder);
    $action = new ChatAction($chatService);

    $response = $action->handle(new ChatRequest(message: 'Hi'));

    expect($response->answer)->toBe('Hello!');
});
