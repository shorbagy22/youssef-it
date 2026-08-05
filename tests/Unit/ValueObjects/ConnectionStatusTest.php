<?php

declare(strict_types=1);

use App\ValueObjects\ConnectionStatus;

test('each case has a human-readable label', function () {
    expect(ConnectionStatus::Connected->label())->toBe('Connected')
        ->and(ConnectionStatus::Disconnected->label())->toBe('Disconnected')
        ->and(ConnectionStatus::NotConfigured->label())->toBe('Not Configured');
});

test('each case has a bootstrap badge variant', function () {
    expect(ConnectionStatus::Connected->badgeVariant())->toBe('success')
        ->and(ConnectionStatus::Disconnected->badgeVariant())->toBe('danger')
        ->and(ConnectionStatus::NotConfigured->badgeVariant())->toBe('secondary');
});

test('backed values match the expected wire format', function () {
    expect(ConnectionStatus::Connected->value)->toBe('connected')
        ->and(ConnectionStatus::Disconnected->value)->toBe('disconnected')
        ->and(ConnectionStatus::NotConfigured->value)->toBe('not_configured');
});
