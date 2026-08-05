<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * The connectivity state of an external system, as shown on the
 * dashboard's status cards.
 *
 * A backed enum rather than a plain string keeps every possible state
 * exhaustively listed and type-checked (by PHPStan and PHP itself)
 * instead of relying on magic string comparisons scattered through the
 * codebase.
 */
enum ConnectionStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case NotConfigured = 'not_configured';

    /**
     * Human-readable label for display on the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
            self::NotConfigured => 'Not Configured',
        };
    }

    /**
     * Bootstrap color variant (e.g. "success", "danger") used to style
     * the status badge for this state.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Disconnected => 'danger',
            self::NotConfigured => 'secondary',
        };
    }
}
