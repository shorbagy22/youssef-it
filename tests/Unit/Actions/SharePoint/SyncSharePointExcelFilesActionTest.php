<?php

declare(strict_types=1);

use App\Actions\SharePoint\SyncSharePointExcelFilesAction;
use App\Contracts\ExcelFileProvider;
use App\DTOs\SharePointExcelFile;
use App\Exceptions\SharePointException;
use App\Repositories\SyncedDocumentRepository;
use App\ValueObjects\ConnectionStatus;
use App\ValueObjects\SyncStatus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('a new remote file is downloaded and stored', function () {
    $provider = Mockery::mock(ExcelFileProvider::class);
    $provider->shouldReceive('healthCheck')->once()->andReturn(ConnectionStatus::Connected);
    $provider->shouldReceive('listExcelFiles')->once()->andReturn([
        new SharePointExcelFile('item-1', 'report.xlsx', new DateTimeImmutable('2026-01-01T00:00:00Z'), 100),
    ]);
    $provider->shouldReceive('downloadFile')->once()->with('item-1')->andReturn('excel-bytes');

    $action = new SyncSharePointExcelFilesAction($provider, new SyncedDocumentRepository);
    $result = $action->handle();

    expect($result->checked)->toBe(1)
        ->and($result->synced)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and($result->failed)->toBe(0)
        ->and($result->notConfigured)->toBeFalse();

    Storage::disk('local')->assertExists('sharepoint-excel/item-1.xlsx');

    $repository = new SyncedDocumentRepository;
    $document = $repository->findBySharePointId('item-1');
    expect($document->sync_status)->toBe(SyncStatus::Synced)
        ->and($document->checksum)->toBe(hash('sha256', 'excel-bytes'));
});

test('an unchanged remote file is skipped without downloading', function () {
    $repository = new SyncedDocumentRepository;
    $repository->markSynced(
        sharePointId: 'item-1',
        fileName: 'report.xlsx',
        modifiedAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        checksum: 'existing-checksum',
        localPath: 'sharepoint-excel/item-1.xlsx',
        size: 100,
    );

    $provider = Mockery::mock(ExcelFileProvider::class);
    $provider->shouldReceive('healthCheck')->once()->andReturn(ConnectionStatus::Connected);
    $provider->shouldReceive('listExcelFiles')->once()->andReturn([
        new SharePointExcelFile('item-1', 'report.xlsx', new DateTimeImmutable('2026-01-01T00:00:00Z'), 100),
    ]);
    $provider->shouldReceive('downloadFile')->never();

    $action = new SyncSharePointExcelFilesAction($provider, $repository);
    $result = $action->handle();

    expect($result->checked)->toBe(1)
        ->and($result->synced)->toBe(0)
        ->and($result->skipped)->toBe(1)
        ->and($result->failed)->toBe(0);
});

test('a changed remote file is re-downloaded', function () {
    $repository = new SyncedDocumentRepository;
    $repository->markSynced(
        sharePointId: 'item-1',
        fileName: 'report.xlsx',
        modifiedAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        checksum: 'old-checksum',
        localPath: 'sharepoint-excel/item-1.xlsx',
        size: 100,
    );

    $provider = Mockery::mock(ExcelFileProvider::class);
    $provider->shouldReceive('healthCheck')->once()->andReturn(ConnectionStatus::Connected);
    $provider->shouldReceive('listExcelFiles')->once()->andReturn([
        new SharePointExcelFile('item-1', 'report.xlsx', new DateTimeImmutable('2026-01-02T00:00:00Z'), 200),
    ]);
    $provider->shouldReceive('downloadFile')->once()->with('item-1')->andReturn('new-excel-bytes');

    $action = new SyncSharePointExcelFilesAction($provider, $repository);
    $result = $action->handle();

    expect($result->synced)->toBe(1)
        ->and($result->skipped)->toBe(0);

    $document = $repository->findBySharePointId('item-1');
    expect($document->checksum)->toBe(hash('sha256', 'new-excel-bytes'));
});

test('a failed download is recorded and does not stop the rest of the sync', function () {
    $repository = new SyncedDocumentRepository;

    $provider = Mockery::mock(ExcelFileProvider::class);
    $provider->shouldReceive('healthCheck')->once()->andReturn(ConnectionStatus::Connected);
    $provider->shouldReceive('listExcelFiles')->once()->andReturn([
        new SharePointExcelFile('item-1', 'broken.xlsx', new DateTimeImmutable('2026-01-01T00:00:00Z'), 100),
        new SharePointExcelFile('item-2', 'good.xlsx', new DateTimeImmutable('2026-01-01T00:00:00Z'), 100),
    ]);
    $provider->shouldReceive('downloadFile')->once()->with('item-1')->andThrow(new SharePointException('boom'));
    $provider->shouldReceive('downloadFile')->once()->with('item-2')->andReturn('good-bytes');

    $action = new SyncSharePointExcelFilesAction($provider, $repository);
    $result = $action->handle();

    expect($result->checked)->toBe(2)
        ->and($result->synced)->toBe(1)
        ->and($result->failed)->toBe(1)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toContain('broken.xlsx');

    expect($repository->findBySharePointId('item-1')->sync_status)->toBe(SyncStatus::Failed)
        ->and($repository->findBySharePointId('item-2')->sync_status)->toBe(SyncStatus::Synced);
});

test('it exits gracefully without listing or downloading anything when SharePoint is not configured', function () {
    $provider = Mockery::mock(ExcelFileProvider::class);
    $provider->shouldReceive('healthCheck')->once()->andReturn(ConnectionStatus::NotConfigured);
    $provider->shouldReceive('listExcelFiles')->never();
    $provider->shouldReceive('downloadFile')->never();

    $action = new SyncSharePointExcelFilesAction($provider, new SyncedDocumentRepository);
    $result = $action->handle();

    expect($result->notConfigured)->toBeTrue()
        ->and($result->checked)->toBe(0)
        ->and($result->synced)->toBe(0)
        ->and($result->failed)->toBe(0);
});
