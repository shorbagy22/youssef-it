<?php

declare(strict_types=1);

use App\Actions\Dashboard\GetSystemStatusAction;
use App\Contracts\ExcelFileProvider;
use App\DTOs\Dashboard\SystemStatusData;
use App\ValueObjects\ConnectionStatus;

test('it reflects the real SharePoint status alongside the still-fake values', function () {
    $excelFiles = Mockery::mock(ExcelFileProvider::class);
    $excelFiles->shouldReceive('healthCheck')->once()->andReturn(ConnectionStatus::Connected);

    $status = (new GetSystemStatusAction($excelFiles))->handle();

    expect($status)->toBeInstanceOf(SystemStatusData::class)
        ->and($status->sharePoint)->toBe(ConnectionStatus::Connected)
        ->and($status->ollama)->toBe(ConnectionStatus::NotConfigured)
        ->and($status->database)->toBe(ConnectionStatus::Connected)
        ->and($status->authentication)->toBe(ConnectionStatus::Connected);
});

test('it shows NotConfigured for SharePoint when it is not set up', function () {
    $excelFiles = Mockery::mock(ExcelFileProvider::class);
    $excelFiles->shouldReceive('healthCheck')->once()->andReturn(ConnectionStatus::NotConfigured);

    $status = (new GetSystemStatusAction($excelFiles))->handle();

    expect($status->sharePoint)->toBe(ConnectionStatus::NotConfigured);
});
