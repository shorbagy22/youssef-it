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

/**
 * Builds an unsigned JWT-shaped string carrying the given claims, for
 * exercising the diagnostic command's claim decoder - never a real
 * signed token, and the signature is never checked either by the token
 * or by the command.
 *
 * @param  array<string, mixed>  $claims
 */
function makeFakeJwt(array $claims): string
{
    $base64url = fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

    $header = $base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = $base64url(json_encode($claims));

    return "{$header}.{$payload}.fake-signature";
}

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
        ->expectsOutputToContain('Token request')
        ->expectsOutputToContain('Site request')
        ->expectsOutputToContain('Authenticated successfully.')
        ->expectsOutputToContain('Shared Documents')
        ->expectsOutputToContain('report.xlsx')
        ->assertExitCode(0);
});

test('it fails cleanly when SharePoint is configured but unreachable', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response('Service Unavailable', 500),
    ]);

    $this->artisan('sharepoint:test')
        ->expectsOutputToContain('Token request')
        ->expectsOutputToContain('HTTP status: 500')
        ->assertExitCode(1);
});

test('it prints the full, untruncated response body and headers on a failed site request', function () {
    $longErrorBody = json_encode([
        'error' => [
            'code' => 'generalException',
            'message' => str_repeat('x', 500), // longer than RequestException's own truncation limit
        ],
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'fake-token'], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response($longErrorBody, 401, ['X-Diagnostic-Header' => 'present']),
    ]);

    $this->artisan('sharepoint:test')
        ->expectsOutputToContain('HTTP status: 401')
        ->expectsOutputToContain('X-Diagnostic-Header: present')
        ->expectsOutputToContain(str_repeat('x', 500))
        ->assertExitCode(1);
});

test('it decodes and prints access token JWT claims when roles are present', function () {
    $jwt = makeFakeJwt([
        'aud' => 'https://graph.microsoft.com',
        'iss' => 'https://sts.windows.net/tenant-abc/',
        'tid' => 'tenant-abc',
        'appid' => 'app-abc',
        'roles' => ['Files.Read.All', 'Sites.Read.All'],
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => $jwt], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response(['id' => 'site-123'], 200),
        'graph.microsoft.com/v1.0/sites/site-123/drives' => Http::response(['value' => []], 200),
    ]);

    $this->artisan('sharepoint:test')
        ->expectsOutputToContain('aud (audience): https://graph.microsoft.com')
        ->expectsOutputToContain('iss (issuer): https://sts.windows.net/tenant-abc/')
        ->expectsOutputToContain('tid (tenant): tenant-abc')
        ->expectsOutputToContain('appid (application id): app-abc')
        ->expectsOutputToContain('roles: Files.Read.All, Sites.Read.All')
        ->expectsOutputToContain('scp (scopes): (not present)');
});

test('it reports roles and scp as not present when absent from the token', function () {
    $jwt = makeFakeJwt([
        'aud' => 'https://graph.microsoft.com',
        'iss' => 'https://sts.windows.net/tenant-abc/',
        'tid' => 'tenant-abc',
        'appid' => 'app-abc',
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => $jwt], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response(['error' => ['code' => 'generalException']], 401),
    ]);

    $this->artisan('sharepoint:test')
        ->expectsOutputToContain('roles: (not present)')
        ->expectsOutputToContain('scp (scopes): (not present)')
        ->assertExitCode(1);
});

test('it reports a token that cannot be decoded as a JWT without failing the command', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'not-a-jwt'], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response(['id' => 'site-123'], 200),
        'graph.microsoft.com/v1.0/sites/site-123/drives' => Http::response([
            'value' => [
                ['id' => 'drive-123', 'name' => 'Shared Documents', 'webUrl' => 'https://contoso.sharepoint.com/sites/TeamSite/Shared%20Documents'],
            ],
        ], 200),
        'graph.microsoft.com/v1.0/drives/drive-123/root:/Daily%20Reports:/children' => Http::response(['value' => []], 200),
    ]);

    $this->artisan('sharepoint:test')
        ->expectsOutputToContain('Could not decode the access token as a JWT.')
        ->assertExitCode(0);
});
