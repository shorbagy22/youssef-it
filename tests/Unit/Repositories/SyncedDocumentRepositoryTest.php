<?php

declare(strict_types=1);

use App\Repositories\SyncedDocumentRepository;
use App\ValueObjects\SyncStatus;

test('findBySharePointId returns null when no matching row exists', function () {
    $repository = new SyncedDocumentRepository;

    expect($repository->findBySharePointId('missing'))->toBeNull();
});

test('markSynced creates a new row for a first-time file', function () {
    $repository = new SyncedDocumentRepository;
    $modifiedAt = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $document = $repository->markSynced(
        sharePointId: 'item-1',
        fileName: 'report.xlsx',
        modifiedAt: $modifiedAt,
        checksum: 'abc123',
        localPath: 'sharepoint-excel/item-1.xlsx',
        size: 1024,
    );

    expect($document->sharepoint_id)->toBe('item-1')
        ->and($document->file_name)->toBe('report.xlsx')
        ->and($document->sync_status)->toBe(SyncStatus::Synced)
        ->and($document->checksum)->toBe('abc123')
        ->and($document->size)->toBe(1024)
        ->and($document->synced_at)->not->toBeNull();

    expect($repository->findBySharePointId('item-1'))->not->toBeNull();
});

test('markSynced updates the existing row for a known file', function () {
    $repository = new SyncedDocumentRepository;

    $repository->markSynced(
        sharePointId: 'item-1',
        fileName: 'report.xlsx',
        modifiedAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        checksum: 'old-checksum',
        localPath: 'sharepoint-excel/item-1.xlsx',
        size: 1024,
    );

    $updated = $repository->markSynced(
        sharePointId: 'item-1',
        fileName: 'report.xlsx',
        modifiedAt: new DateTimeImmutable('2026-01-02T00:00:00Z'),
        checksum: 'new-checksum',
        localPath: 'sharepoint-excel/item-1.xlsx',
        size: 2048,
    );

    expect($updated->checksum)->toBe('new-checksum')
        ->and($updated->size)->toBe(2048);
});

test('markFailed records a failed sync attempt', function () {
    $repository = new SyncedDocumentRepository;

    $document = $repository->markFailed('item-1', 'report.xlsx');

    expect($document->sync_status)->toBe(SyncStatus::Failed)
        ->and($document->file_name)->toBe('report.xlsx');
});
