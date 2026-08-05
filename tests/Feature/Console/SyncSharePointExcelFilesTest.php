<?php

declare(strict_types=1);

use App\Models\SyncedDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    config([
        'sharepoint.tenant_id' => 'tenant-123',
        'sharepoint.client_id' => 'client-123',
        'sharepoint.client_secret' => 'secret-123',
        'sharepoint.site_id' => 'site-123',
        'sharepoint.drive_id' => 'drive-123',
        'sharepoint.folder_path' => '',
    ]);
});

test('the sync command downloads new Excel files and records them in MySQL', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 3599,
            'access_token' => 'fake-token',
        ], 200),
        'graph.microsoft.com/v1.0/drives/drive-123/root/children' => Http::response([
            'value' => [
                [
                    'id' => 'item-1',
                    'name' => 'report.xlsx',
                    'size' => 11,
                    'lastModifiedDateTime' => '2026-01-01T00:00:00Z',
                    'file' => ['mimeType' => 'application/vnd.openxmlformats'],
                ],
            ],
        ], 200),
        'graph.microsoft.com/v1.0/drives/drive-123/items/item-1/content' => Http::response('excel-bytes', 200),
    ]);

    $this->artisan('sharepoint:sync-excel')
        ->expectsOutputToContain('Checked 1 file(s): 1 synced, 0 unchanged, 0 failed.')
        ->assertExitCode(0);

    $document = SyncedDocument::query()->where('sharepoint_id', 'item-1')->first();

    expect($document)->not->toBeNull()
        ->and($document->file_name)->toBe('report.xlsx')
        ->and($document->checksum)->toBe(hash('sha256', 'excel-bytes'));

    Storage::disk('local')->assertExists('sharepoint-excel/item-1.xlsx');
});

test('the sync command fails cleanly when SharePoint cannot be reached', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response('Service Unavailable', 500),
    ]);

    $this->artisan('sharepoint:sync-excel')
        ->assertExitCode(1);
});
