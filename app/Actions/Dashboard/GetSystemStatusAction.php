<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\DTOs\Dashboard\SystemStatusData;
use App\ValueObjects\ConnectionStatus;

/**
 * Builds the system status snapshot shown on the dashboard.
 *
 * Every status is hardcoded for Phase 1, per the explicit "use fake
 * status for now" requirement - no SharePoint, Ollama, or chat
 * integration exists yet. Each value below notes what its real check
 * will become once that milestone lands; this Action is the only place
 * that will need to change - DashboardController and the dashboard view
 * depend solely on the SystemStatusData shape.
 */
final class GetSystemStatusAction
{
    /**
     * Build the current (currently fake) system status snapshot.
     */
    public function handle(): SystemStatusData
    {
        return new SystemStatusData(
            // Future: a Microsoft Graph call using config('sharepoint.*').
            sharePoint: ConnectionStatus::NotConfigured,

            // Future: an HTTP call to config('ollama.base_url').
            ollama: ConnectionStatus::NotConfigured,

            // Future: a real DB ping. Left fake here for consistency
            // with the other three cards in this milestone.
            database: ConnectionStatus::Connected,

            // Future: reflect the current request's auth state. Left
            // fake here for consistency with the other three cards.
            authentication: ConnectionStatus::Connected,
        );
    }
}
