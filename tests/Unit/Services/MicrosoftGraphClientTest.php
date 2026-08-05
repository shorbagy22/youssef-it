<?php

declare(strict_types=1);

use App\Exceptions\SharePointException;
use App\Services\MicrosoftGraphClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'sharepoint.tenant_id' => 'tenant-123',
        'sharepoint.client_id' => 'client-123',
        'sharepoint.client_secret' => 'secret-123',
        'sharepoint.drive_id' => 'drive-123',
        'sharepoint.folder_path' => '',
    ]);
});

/**
 * @return array<string, mixed>
 */
function sharePointFakeTokenResponse(): array
{
    return [
        'login.microsoftonline.com/*' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 3599,
            'access_token' => 'fake-token',
        ], 200),
    ];
}

test('listChildren returns the raw driveItem list on success', function () {
    Http::fake([
        ...sharePointFakeTokenResponse(),
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

test('listChildren scopes to the configured folder path when set', function () {
    config(['sharepoint.folder_path' => 'Reports/Daily']);

    Http::fake([
        ...sharePointFakeTokenResponse(),
        'graph.microsoft.com/v1.0/drives/drive-123/root:/Reports/Daily:/children' => Http::response(['value' => []], 200),
    ]);

    $client = new MicrosoftGraphClient;
    $client->listChildren();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/root:/Reports/Daily:/children'));
});

test('getItemMetadata returns the raw driveItem resource', function () {
    Http::fake([
        ...sharePointFakeTokenResponse(),
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
        ...sharePointFakeTokenResponse(),
        'graph.microsoft.com/v1.0/drives/drive-123/items/item-1/content' => Http::response('binary-excel-bytes', 200),
    ]);

    $client = new MicrosoftGraphClient;
    $content = $client->downloadContent('item-1');

    expect($content)->toBe('binary-excel-bytes');
});

test('the access token is fetched once and reused across multiple calls', function () {
    Http::fake([
        ...sharePointFakeTokenResponse(),
        'graph.microsoft.com/v1.0/drives/drive-123' => Http::response(['id' => 'drive-123'], 200),
        'graph.microsoft.com/v1.0/drives/drive-123/root/children' => Http::response(['value' => []], 200),
    ]);

    $client = new MicrosoftGraphClient;
    $client->healthCheck();
    $client->listChildren();

    Http::assertSentCount(3); // one token request + two Graph requests
});

test('healthCheck returns true when the drive is reachable', function () {
    Http::fake([
        ...sharePointFakeTokenResponse(),
        'graph.microsoft.com/v1.0/drives/drive-123' => Http::response(['id' => 'drive-123'], 200),
    ]);

    $client = new MicrosoftGraphClient;

    expect($client->healthCheck())->toBeTrue();
});

test('healthCheck returns false when the drive is unreachable', function () {
    Http::fake([
        ...sharePointFakeTokenResponse(),
        'graph.microsoft.com/v1.0/drives/drive-123' => Http::response('Not Found', 404),
    ]);

    $client = new MicrosoftGraphClient;

    expect($client->healthCheck())->toBeFalse();
});

test('a Graph request throws SharePointException when the connection fails', function () {
    Http::fake([
        ...sharePointFakeTokenResponse(),
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
