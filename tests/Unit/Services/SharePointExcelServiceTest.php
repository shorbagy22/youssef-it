<?php

declare(strict_types=1);

use App\DTOs\SharePointExcelFile;
use App\Exceptions\SharePointException;
use App\Services\MicrosoftGraphClient;
use App\Services\SharePointExcelService;
use App\ValueObjects\ConnectionStatus;
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
 * @return array<string, mixed>
 */
function excelServiceFakeAuthAndResolution(): array
{
    return [
        'login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'fake-token'], 200),
        'graph.microsoft.com/v1.0/sites/contoso.sharepoint.com:/sites/TeamSite' => Http::response(['id' => 'site-123'], 200),
        'graph.microsoft.com/v1.0/sites/site-123/drives' => Http::response([
            'value' => [
                ['id' => 'drive-123', 'name' => 'Shared Documents', 'webUrl' => 'https://contoso.sharepoint.com/sites/TeamSite/Shared%20Documents'],
            ],
        ], 200),
    ];
}

function makeSharePointExcelService(): SharePointExcelService
{
    return new SharePointExcelService(new MicrosoftGraphClient);
}

test('listExcelFiles returns only .xlsx and .xls files, skipping folders and other types', function () {
    Http::fake([
        ...excelServiceFakeAuthAndResolution(),
        'graph.microsoft.com/v1.0/drives/drive-123/root/children' => Http::response([
            'value' => [
                ['id' => '1', 'name' => 'report.xlsx', 'size' => 100, 'lastModifiedDateTime' => '2026-01-01T00:00:00Z', 'file' => ['mimeType' => 'application/vnd.openxmlformats']],
                ['id' => '2', 'name' => 'legacy.xls', 'size' => 50, 'lastModifiedDateTime' => '2026-01-02T00:00:00Z', 'file' => ['mimeType' => 'application/vnd.ms-excel']],
                ['id' => '3', 'name' => 'notes.docx', 'size' => 20, 'lastModifiedDateTime' => '2026-01-03T00:00:00Z', 'file' => ['mimeType' => 'application/msword']],
                ['id' => '4', 'name' => 'Subfolder', 'folder' => ['childCount' => 2]],
            ],
        ], 200),
    ]);

    $files = makeSharePointExcelService()->listExcelFiles();

    expect($files)->toHaveCount(2)
        ->and($files[0])->toBeInstanceOf(SharePointExcelFile::class)
        ->and($files[0]->name)->toBe('report.xlsx')
        ->and($files[1]->name)->toBe('legacy.xls');
});

test('listExcelFiles throws when an Excel file record is missing required fields', function () {
    Http::fake([
        ...excelServiceFakeAuthAndResolution(),
        'graph.microsoft.com/v1.0/drives/drive-123/root/children' => Http::response([
            'value' => [
                // Passes the "is this an Excel file" filter (has a
                // .xlsx name and a "file" facet) but is missing id,
                // size, and lastModifiedDateTime.
                ['name' => 'incomplete.xlsx', 'file' => ['mimeType' => 'application/vnd.openxmlformats']],
            ],
        ], 200),
    ]);

    expect(fn () => makeSharePointExcelService()->listExcelFiles())
        ->toThrow(SharePointException::class);
});

test('downloadFile returns the raw file content', function () {
    Http::fake([
        ...excelServiceFakeAuthAndResolution(),
        'graph.microsoft.com/v1.0/drives/drive-123/items/item-1/content' => Http::response('binary-excel-bytes', 200),
    ]);

    $content = makeSharePointExcelService()->downloadFile('item-1');

    expect($content)->toBe('binary-excel-bytes');
});

test('healthCheck delegates to the Graph client', function () {
    Http::fake([...excelServiceFakeAuthAndResolution()]);

    expect(makeSharePointExcelService()->healthCheck())->toBe(ConnectionStatus::Connected);
});

test('healthCheck returns NotConfigured when the site URL is empty', function () {
    config(['sharepoint.site_url' => '']);

    expect(makeSharePointExcelService()->healthCheck())->toBe(ConnectionStatus::NotConfigured);
});
