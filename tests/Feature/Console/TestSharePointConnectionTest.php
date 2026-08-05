<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'sharepoint.tenant_id' => 'tenant-123',
        'sharepoint.client_id' => 'client-123',
        'sharepoint.client_secret' => 'secret-123',
        'sharepoint.site_url' => 'https://contoso.sharepoint.com/sites/TeamSite',
        'sharepoint.document_library' => 'Shared Documents',
        'sharepoint.excel_folder' => 'Daily Reports',
    ]);
});

test('it reports not configured without making any HTTP call', function () {
    config(['sharepoint.site_url' => '']);

    Http::fake();

    $this->artisan('sharepoint:test')
        ->expectsOutputToContain('SharePoint is not configured.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('it authenticates, resolves the site, and lists libraries and Excel files when configured', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'fake-token'], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response(['id' => 'site-123'], 200),
        'graph.microsoft.com/v1.0/sites/site-123/drives' => Http::response([
            'value' => [
                ['id' => 'drive-123', 'name' => 'Shared Documents', 'webUrl' => 'https://contoso.sharepoint.com/sites/TeamSite/Shared%20Documents'],
            ],
        ], 200),
        'graph.microsoft.com/v1.0/drives/drive-123/root:/Daily%20Reports:/children' => Http::response([
            'value' => [
                ['id' => 'item-1', 'name' => 'report.xlsx', 'size' => 10, 'lastModifiedDateTime' => '2026-01-01T00:00:00Z', 'file' => ['mimeType' => 'application/vnd.openxmlformats']],
            ],
        ], 200),
    ]);

    $this->artisan('sharepoint:test')
        ->expectsOutputToContain('Authenticated successfully.')
        ->expectsOutputToContain('Resolved site ID: site-123')
        ->expectsOutputToContain('Shared Documents')
        ->expectsOutputToContain('report.xlsx')
        ->assertExitCode(0);
});

test('it fails cleanly when SharePoint is configured but unreachable', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response('Service Unavailable', 500),
    ]);

    $this->artisan('sharepoint:test')
        ->assertExitCode(1);
});
