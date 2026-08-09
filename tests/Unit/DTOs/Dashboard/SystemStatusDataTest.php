<?php

declare(strict_types=1);

use App\DTOs\Dashboard\SystemStatusData;
use App\ValueObjects\ConnectionStatus;

test('it exposes each connection status by name', function () {
    $data = new SystemStatusData(
        aiService: ConnectionStatus::Disconnected,
        database: ConnectionStatus::Connected,
        authentication: ConnectionStatus::Connected,
    );

    expect($data->aiService)->toBe(ConnectionStatus::Disconnected)
        ->and($data->database)->toBe(ConnectionStatus::Connected)
        ->and($data->authentication)->toBe(ConnectionStatus::Connected);
});
