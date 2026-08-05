<?php

declare(strict_types=1);

use App\Exceptions\SharePointException;
use App\Services\MicrosoftGraphClient;
use App\ValueObjects\ConnectionStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'sharepoint.tenant_id' => 'tenant-123',
        'sharepoint.client_id' => 'client-123',
        'sharepoint.client_secret' => 'secret-123',
        'sharepoint.site_url' => 'https://contoso.sharepoint.com/sites/TeamSite',
        'sharepoint.document_library' => 'Shared Documents',
        'sharepoint.excel_folder' => '',
    ]);
});

/**
 * Standard fakes for the auth + site-resolution + drive-resolution chain
 * every Graph call goes through before it can do anything else. Tests
 * add their own fake for whatever endpoint they're actually exercising
 * on top of this.
 *
 * @return array<string, mixed>
 */
function sharePointFakeAuthAndResolution(): array
{
    return [
        'login.microsoftonline.com/*' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 3599,
            'access_token' => 'fake-token',
        ], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response([
            'id' => 'site-123',
        ], 200),
        'graph.microsoft.com/v1.0/sites/site-123/drives' => Http::response([
            'value' => [
                ['id' => 'drive-123', 'name' => 'Shared Documents', 'webUrl' => 'https://contoso.sharepoint.com/sites/TeamSite/Shared%20Documents'],
            ],
        ], 200),
    ];
}

test('listChildren returns the raw driveItem list on success', function () {
    Http::fake([
        ...sharePointFakeAuthAndResolution(),
        'graph.microsoft.com/v1.0/drives/drive-123/root/children' => Http::response([
            'value' => [
                ['id' => '1', 'name' => 'a.xlsx'],
                ['id' => '2', 'name' => 'b.xlsx'],
            ],
        ], 200),
    ]);

    $client = new MicrosoftGraphClient;
    $items = $client->listChildren();

    expect($items)->toHaveCount(2)
        ->and($items[0]['id'])->toBe('1');
});

test('listChildren scopes to the configured Excel folder when set', function () {
    config(['sharepoint.excel_folder' => 'Reports/Daily']);

    Http::fake([
        ...sharePointFakeAuthAndResolution(),
        'graph.microsoft.com/v1.0/drives/drive-123/root:/Reports/Daily:/children' => Http::response(['value' => []], 200),
    ]);

    $client = new MicrosoftGraphClient;
    $client->listChildren();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/root:/Reports/Daily:/children'));
});

test('getItemMetadata returns the raw driveItem resource', function () {
    Http::fake([
        ...sharePointFakeAuthAndResolution(),
        'graph.microsoft.com/v1.0/drives/drive-123/items/item-1' => Http::response([
            'id' => 'item-1',
            'name' => 'a.xlsx',
        ], 200),
    ]);

    $client = new MicrosoftGraphClient;
    $item = $client->getItemMetadata('item-1');

    expect($item['id'])->toBe('item-1');
});

test('downloadContent returns the raw file body', function () {
    Http::fake([
        ...sharePointFakeAuthAndResolution(),
        'graph.microsoft.com/v1.0/drives/drive-123/items/item-1/content' => Http::response('binary-excel-bytes', 200),
    ]);

    $client = new MicrosoftGraphClient;
    $content = $client->downloadContent('item-1');

    expect($content)->toBe('binary-excel-bytes');
});

test('the access token, site ID, and drive ID are all resolved once and reused', function () {
    Http::fake([
        ...sharePointFakeAuthAndResolution(),
        'graph.microsoft.com/v1.0/drives/drive-123/root/children' => Http::response(['value' => []], 200),
    ]);

    $client = new MicrosoftGraphClient;
    $client->healthCheck();
    $client->listChildren();

    // token + site resolve + drives list (all three inside healthCheck's
    // first resolution) + one more for listChildren's own request.
    Http::assertSentCount(4);
});

test('listDocumentLibraries returns every drive on the site', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'fake-token'], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response(['id' => 'site-123'], 200),
        'graph.microsoft.com/v1.0/sites/site-123/drives' => Http::response([
            'value' => [
                ['id' => 'd1', 'name' => 'Documents', 'webUrl' => 'https://x/Shared%20Documents'],
                ['id' => 'd2', 'name' => 'Other Library', 'webUrl' => 'https://x/Other%20Library'],
            ],
        ], 200),
    ]);

    $client = new MicrosoftGraphClient;
    $libraries = $client->listDocumentLibraries();

    expect($libraries)->toHaveCount(2)
        ->and($libraries[0]['name'])->toBe('Documents')
        ->and($libraries[1]['name'])->toBe('Other Library');
});

test('the document library falls back to matching by webUrl when the name differs', function () {
    // Graph's default document library is internally named "Documents"
    // even though SharePoint's UI (and the configured "Shared Documents"
    // default) shows it differently - this is the fallback that handles it.
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'fake-token'], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response(['id' => 'site-123'], 200),
        'graph.microsoft.com/v1.0/sites/site-123/drives' => Http::response([
            'value' => [
                ['id' => 'drive-999', 'name' => 'Documents', 'webUrl' => 'https://contoso.sharepoint.com/sites/TeamSite/Shared%20Documents'],
            ],
        ], 200),
        'graph.microsoft.com/v1.0/drives/drive-999/root/children' => Http::response(['value' => []], 200),
    ]);

    $client = new MicrosoftGraphClient;
    $client->listChildren();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/drives/drive-999/'));
});

test('resolveSiteId resolves a root site with no server-relative path', function () {
    config(['sharepoint.site_url' => 'https://contoso.sharepoint.com']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'fake-token'], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com' => Http::response(['id' => 'root-site'], 200),
    ]);

    $client = new MicrosoftGraphClient;

    expect($client->resolveSiteId())->toBe('root-site');
});

test('a Graph request throws SharePointException when no matching document library exists', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'fake-token'], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response(['id' => 'site-123'], 200),
        'graph.microsoft.com/v1.0/sites/site-123/drives' => Http::response(['value' => []], 200),
    ]);

    $client = new MicrosoftGraphClient;

    expect(fn () => $client->listChildren())->toThrow(SharePointException::class);
});

test('healthCheck returns NotConfigured when the site URL is empty, without making any HTTP call', function () {
    config(['sharepoint.site_url' => '']);

    $client = new MicrosoftGraphClient;

    expect($client->healthCheck())->toBe(ConnectionStatus::NotConfigured);

    Http::fake();
    Http::assertNothingSent();
});

test('healthCheck returns Connected when the site and drive resolve successfully', function () {
    Http::fake([...sharePointFakeAuthAndResolution()]);

    $client = new MicrosoftGraphClient;

    expect($client->healthCheck())->toBe(ConnectionStatus::Connected);
});

test('healthCheck returns Disconnected when the site cannot be resolved', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'fake-token'], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response('Not Found', 404),
    ]);

    $client = new MicrosoftGraphClient;

    expect($client->healthCheck())->toBe(ConnectionStatus::Disconnected);
});

test('a Graph request throws SharePointException when the connection fails', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'fake-token'], 200),
        'graph.microsoft.com/*' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    $client = new MicrosoftGraphClient;

    expect(fn () => $client->listChildren())->toThrow(SharePointException::class);
});

test('authentication failure throws SharePointException', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $client = new MicrosoftGraphClient;

    expect(fn () => $client->listChildren())->toThrow(SharePointException::class);
});
