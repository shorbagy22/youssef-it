<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\UploadedFile;

afterEach(function () {
    Source::flushEventListeners();
});

test('creating a file-type source moves the file to disk and creates a Source row', function () {
    $user = User::factory()->create();
    $department = Department::where('slug', 'quality')->first();

    $file = UploadedFile::fake()->create('report.xlsx', 100);

    $response = $this->actingAs($user)->post('/admin/sources', [
        'name' => 'Test Source',
        'department_id' => $department->id,
        'type' => 'file',
        'file' => $file,
    ]);

    $response->assertRedirect(route('admin.sources.index'));

    $source = Source::query()->where('name', 'Test Source')->first();

    expect($source)->not->toBeNull()
        ->and($source->file_path)->not->toBeNull()
        ->and(file_exists($source->file_path))->toBeTrue();

    @unlink($source->file_path);
});

test('creating a PDF-type source is accepted, same as a spreadsheet', function () {
    $user = User::factory()->create();
    $department = Department::where('slug', 'quality')->first();

    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    $response = $this->actingAs($user)->post('/admin/sources', [
        'name' => 'Test PDF Source',
        'department_id' => $department->id,
        'type' => 'file',
        'file' => $file,
    ]);

    $response->assertRedirect(route('admin.sources.index'));

    $source = Source::query()->where('name', 'Test PDF Source')->first();

    expect($source)->not->toBeNull()
        ->and($source->file_path)->not->toBeNull()
        ->and(file_exists($source->file_path))->toBeTrue();

    @unlink($source->file_path);
});

test('a file type not in the allowed list (e.g. .docx) is rejected', function () {
    $user = User::factory()->create();
    $department = Department::where('slug', 'quality')->first();

    $file = UploadedFile::fake()->create('report.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $response = $this->actingAs($user)->post('/admin/sources', [
        'name' => 'Should Be Rejected',
        'department_id' => $department->id,
        'type' => 'file',
        'file' => $file,
    ]);

    $response->assertSessionHasErrors('file');
    expect(Source::query()->where('name', 'Should Be Rejected')->exists())->toBeFalse();
});

test('if Source::create() fails after the file is uploaded, the file is deleted instead of orphaned', function () {
    $user = User::factory()->create();
    $department = Department::where('slug', 'quality')->first();

    $before = glob(storage_path('app/data/*')) ?: [];

    // Simulates a DB-level failure happening AFTER the file has already
    // been moved to disk - exactly the scenario that used to leave a
    // file with no corresponding Source row.
    Source::creating(function (): void {
        throw new RuntimeException('Simulated DB failure');
    });

    $file = UploadedFile::fake()->create('report.xlsx', 100);

    $response = $this->actingAs($user)->post('/admin/sources', [
        'name' => 'Should Not Persist',
        'department_id' => $department->id,
        'type' => 'file',
        'file' => $file,
    ]);

    $response->assertSessionHas('error');

    expect(Source::query()->where('name', 'Should Not Persist')->exists())->toBeFalse();

    // The critical assertion: no new file left behind in storage/app/data.
    $after = glob(storage_path('app/data/*')) ?: [];
    expect($after)->toEqual($before);
});

test('if Source::update() fails after a replacement file is uploaded, the new file is deleted, not the old one', function () {
    $user = User::factory()->create();
    $department = Department::where('slug', 'quality')->first();

    $existingPath = storage_path('app/data/'.uniqid('existing_', true).'.xlsx');
    file_put_contents($existingPath, 'original content');

    $source = Source::factory()->create([
        'department_id' => $department->id,
        'type' => 'file',
        'file_path' => $existingPath,
    ]);

    $before = glob(storage_path('app/data/*')) ?: [];

    Source::updating(function (): void {
        throw new RuntimeException('Simulated DB failure');
    });

    $file = UploadedFile::fake()->create('replacement.xlsx', 100);

    $response = $this->actingAs($user)->put("/admin/sources/{$source->id}", [
        'name' => 'Updated Name',
        'department_id' => $department->id,
        'type' => 'file',
        'file' => $file,
    ]);

    $response->assertSessionHas('error');

    // The original file must be untouched - only the failed replacement
    // upload should be cleaned up.
    expect(file_exists($existingPath))->toBeTrue()
        ->and($source->fresh()->file_path)->toBe($existingPath);

    $after = glob(storage_path('app/data/*')) ?: [];
    expect($after)->toEqual($before);

    @unlink($existingPath);
});
