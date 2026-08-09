<?php

declare(strict_types=1);

use App\Models\Source;

test('it lists every configured source', function () {
    Source::factory()->count(2)->create();

    $this->getJson('/api/sources')
        ->assertOk()
        ->assertJsonCount(2);
});

test('it creates a file-type source', function () {
    $this->postJson('/api/sources', [
        'department' => 'quality',
        'name' => 'Daily Quality Report',
        'type' => 'file',
        'file_path' => 'D:\\data\\quality\\report.xlsx',
    ])
        ->assertCreated()
        ->assertJsonFragment(['department' => 'quality', 'type' => 'file']);

    expect(Source::query()->count())->toBe(1);
});

test('it creates a url-type source', function () {
    $this->postJson('/api/sources', [
        'department' => 'it',
        'name' => 'IT Metrics',
        'type' => 'url',
        'url' => 'https://example.test/report.xlsx',
    ])
        ->assertCreated()
        ->assertJsonFragment(['type' => 'url']);
});

test('file_path is required when type is file', function () {
    $this->postJson('/api/sources', [
        'department' => 'quality',
        'name' => 'Daily Quality Report',
        'type' => 'file',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file_path');
});

test('url is required when type is url', function () {
    $this->postJson('/api/sources', [
        'department' => 'quality',
        'name' => 'Daily Quality Report',
        'type' => 'url',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('url');
});

test('department must be one of the known values', function () {
    $this->postJson('/api/sources', [
        'department' => 'finance',
        'name' => 'Report',
        'type' => 'file',
        'file_path' => 'D:\\data\\report.xlsx',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department');
});

test('type must be file or url', function () {
    $this->postJson('/api/sources', [
        'department' => 'quality',
        'name' => 'Report',
        'type' => 'ftp',
        'file_path' => 'D:\\data\\report.xlsx',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});
