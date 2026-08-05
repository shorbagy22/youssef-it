<?php

declare(strict_types=1);

use App\Actions\Dashboard\GetSystemStatusAction;
use App\DTOs\Dashboard\SystemStatusData;
use App\ValueObjects\ConnectionStatus;

test('it returns the phase 1 fake status snapshot', function () {
    $status = (new GetSystemStatusAction)->handle();

    expect($status)->toBeInstanceOf(SystemStatusData::class)
        ->and($status->sharePoint)->toBe(ConnectionStatus::NotConfigured)
        ->and($status->ollama)->toBe(ConnectionStatus::NotConfigured)
        ->and($status->database)->toBe(ConnectionStatus::Connected)
        ->and($status->authentication)->toBe(ConnectionStatus::Connected);
});
