<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Contracts\LLMClient;
use App\DTOs\Dashboard\SystemStatusData;
use App\ValueObjects\ConnectionStatus;

/**
 * Builds the system status snapshot shown on the dashboard.
 *
 * The AI service card is real - LLMClient::isHealthy() never throws, so
 * this Action doesn't need to catch anything around it. Database and
 * authentication are still hardcoded for now; each comment below notes
 * what its real check will become.
 */
final class GetSystemStatusAction
{
    public function __construct(
        private readonly LLMClient $llmClient,
    ) {}

    public function handle(): SystemStatusData
    {
        return new SystemStatusData(
            aiService: $this->llmClient->isHealthy() ? ConnectionStatus::Connected : ConnectionStatus::Disconnected,

            // Future: a real DB ping. Left fake here for consistency
            // with the card above.
            database: ConnectionStatus::Connected,

            // Future: reflect the current request's auth state. Left
            // fake here for consistency with the card above.
            authentication: ConnectionStatus::Connected,
        );
    }
}
