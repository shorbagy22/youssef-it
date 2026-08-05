<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SharePoint / Microsoft Graph Configuration
|--------------------------------------------------------------------------
|
| Connection details for authenticating against Microsoft Graph (client
| credentials / app-only OAuth2 flow) and locating the SharePoint document
| library the chatbot reads from. Nothing in the application reads these
| values yet - SharePoint integration is a later milestone. Defined now so
| the configuration surface is settled before that work begins.
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

    // The Microsoft Graph site ID (not the site URL) hosting the document
    // library, e.g. resolved once via GET /sites/{hostname}:/{path}.
    'site_id' => env('SHAREPOINT_SITE_ID'),

    // The Microsoft Graph drive ID (document library) within the site
    // above to read documents from.
    'drive_id' => env('SHAREPOINT_DRIVE_ID'),

];
