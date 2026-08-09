<?php

declare(strict_types=1);

use App\Actions\Dashboard\GetSystemStatusAction;
use App\Contracts\LLMClient;
use App\DTOs\Dashboard\SystemStatusData;
use App\ValueObjects\ConnectionStatus;

test('it reflects the real AI service status alongside the still-fake values', function () {
    $llmClient = Mockery::mock(LLMClient::class);
    $llmClient->shouldReceive('isHealthy')->once()->andReturn(true);

    $status = (new GetSystemStatusAction($llmClient))->handle();

    expect($status)->toBeInstanceOf(SystemStatusData::class)
        ->and($status->aiService)->toBe(ConnectionStatus::Connected)
        ->and($status->database)->toBe(ConnectionStatus::Connected)
        ->and($status->authentication)->toBe(ConnectionStatus::Connected);
});

test('it shows Disconnected for the AI service when it is unreachable', function () {
    $llmClient = Mockery::mock(LLMClient::class);
    $llmClient->shouldReceive('isHealthy')->once()->andReturn(false);

    $status = (new GetSystemStatusAction($llmClient))->handle();

    expect($status->aiService)->toBe(ConnectionStatus::Disconnected);
});
