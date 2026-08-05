<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SharePoint / Microsoft Graph Configuration
|--------------------------------------------------------------------------
|
| Connection details for authenticating against Microsoft Graph (client
| credentials / app-only OAuth2 flow) and locating the SharePoint document
| library that Excel files are synced from. Used by
| App\Services\MicrosoftGraphClient. See docs/sharepoint.md and
| docs/sharepoint-setup.md.
|
| The Site ID and Drive ID are never configured directly - they're
| resolved dynamically from site_url/document_library at runtime, so
| filling in these values is the only setup step ever required; no code
| changes.
|
*/

return [

    // Azure AD tenant ID the app registration belongs to.
    'tenant_id' => env('SHAREPOINT_TENANT_ID'),

    // Azure AD app registration (client) ID used for the client
    // credentials OAuth2 flow.
    'client_id' => env('SHAREPOINT_CLIENT_ID'),

    // Azure AD app registration client secret. Never log this value.
    'client_secret' => env('SHAREPOINT_CLIENT_SECRET'),

    // Full URL of the SharePoint site, e.g.
    // "https://contoso.sharepoint.com/sites/TeamSite". Left empty means
    // SharePoint is not configured yet - MicrosoftGraphClient::healthCheck()
    // returns ConnectionStatus::NotConfigured and nothing else attempts a
    // Graph call.
    'site_url' => env('SHAREPOINT_SITE_URL', ''),

    // Name of the document library to sync Excel files from, as shown in
    // the SharePoint UI (e.g. "Shared Documents"). Matched against Graph's
    // drive resource for the site - see MicrosoftGraphClient for the
    // matching rules (Graph's default library is internally named
    // "Documents", not "Shared Documents", which is handled there).
    'document_library' => env('SHAREPOINT_DOCUMENT_LIBRARY', 'Shared Documents'),

    // Subfolder within the document library containing the Excel files.
    'excel_folder' => env('SHAREPOINT_EXCEL_FOLDER', 'Daily Reports'),

    // Cron expression controlling how often `sharepoint:sync-excel` runs
    // (see routes/console.php). Default: daily at 2am.
    'sync_schedule' => env('SHAREPOINT_SYNC_SCHEDULE', '0 2 * * *'),

];
