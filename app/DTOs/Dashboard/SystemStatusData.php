<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\Actions\Dashboard\GetSystemStatusAction;
use App\ValueObjects\ConnectionStatus;

/**
 * Immutable snapshot of every external system's connectivity state, shown
 * as status cards on the dashboard.
 *
 * Built by {@see GetSystemStatusAction}. The
 * dashboard view only ever depends on this DTO's shape, so replacing a
 * fake status with a real check in the Action later requires no view or
 * controller changes.
 */
final readonly class SystemStatusData
{
    public function __construct(
        public ConnectionStatus $aiService,
        public ConnectionStatus $database,
        public ConnectionStatus $authentication,
    ) {}
}
