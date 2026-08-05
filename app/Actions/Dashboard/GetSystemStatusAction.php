<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Contracts\ExcelFileProvider;
use App\DTOs\Dashboard\SystemStatusData;
use App\ValueObjects\ConnectionStatus;

/**
 * Builds the system status snapshot shown on the dashboard.
 *
 * SharePoint's status is real - ExcelFileProvider::healthCheck() never
 * throws, so this Action doesn't need to catch anything around it: it
 * returns NotConfigured when SHAREPOINT_SITE_URL is empty, Connected or
 * Disconnected once it's set. Ollama, database, and authentication are
 * still hardcoded for now; each comment below notes what its real check
 * will become.
 */
final class GetSystemStatusAction
{
    public function __construct(
        private readonly ExcelFileProvider $excelFiles,
    ) {}

    public function handle(): SystemStatusData
    {
        return new SystemStatusData(
            sharePoint: $this->excelFiles->healthCheck(),

            // Future: OllamaClient::isHealthy().
            ollama: ConnectionStatus::NotConfigured,

            // Future: a real DB ping. Left fake here for consistency
            // with the two cards above.
            database: ConnectionStatus::Connected,

            // Future: reflect the current request's auth state. Left
            // fake here for consistency with the two cards above.
            authentication: ConnectionStatus::Connected,
        );
    }
}
