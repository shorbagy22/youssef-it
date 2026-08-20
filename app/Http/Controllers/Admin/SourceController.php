<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSourcesJob;
use App\Models\Department;
use App\Models\Source;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Authenticated admin UI for managing Sources - a Blade counterpart to
 * Api\SourceController's POST /api/sources, not a replacement for it.
 * Uploaded files are stored under storage/app/data/ and referenced by
 * absolute path in file_path, matching how SyncSourcesAction reads
 * file-type sources directly off disk (no Storage disk abstraction).
 *
 * store()/update() guard against orphan files: a DB transaction can't
 * "undo" a filesystem write, so if Source::create()/update() fails after
 * a file was already moved onto disk, the catch block explicitly deletes
 * it - a failed save should never silently leave a file with no
 * corresponding Source row (that file is then invisible to sources:sync
 * and to the admin, since nothing points at it).
 */
final class SourceController extends Controller
{
    public function index(): View
    {
        return view('admin.sources.index', [
            'sources' => Source::query()->with('department')->latest()->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.sources.create', [
            'departments' => Department::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'type' => ['required', 'string', Rule::in(['file', 'url'])],
            'file' => ['required_if:type,file', 'nullable', 'file', 'mimes:xlsx,xls,csv,ods,xlsm,pdf', 'max:20480'],
            'url' => ['required_if:type,url', 'nullable', 'string', 'url', 'max:2048'],
            'ocr' => ['sometimes', 'boolean'],
        ]);

        $filePath = null;

        try {
            if ($validated['type'] === 'file') {
                $filePath = $this->storeUploadedFile($request);
            }

            // DB::transaction() is defensive (protects against a
            // half-written row if the insert itself is interrupted) - a
            // single INSERT is already atomic at the DB level, so this
            // alone does NOT prevent orphan files. The catch block below
            // is what actually does that.
            DB::transaction(function () use ($request, $validated, $filePath): void {
                Source::query()->create([
                    'name' => $validated['name'],
                    'department_id' => $validated['department_id'],
                    'type' => $validated['type'],
                    'file_path' => $filePath,
                    'url' => $validated['type'] === 'url' ? $validated['url'] : null,
                    'ocr' => $request->boolean('ocr'),
                ]);
            });
        } catch (Throwable $e) {
            $this->cleanUpAndLogFailure($filePath, $e, [
                'action' => 'store',
                'name' => $validated['name'],
                'department_id' => $validated['department_id'],
                'type' => $validated['type'],
            ]);

            return back()
                ->withInput()
                ->with('error', 'Could not save this source - the uploaded file was not kept. '.$e->getMessage());
        }

        return redirect()
            ->route('admin.sources.index')
            ->with('status', 'Source created.');
    }

    public function sync(): RedirectResponse
    {
        SyncSourcesJob::dispatch();

        return redirect()
            ->route('admin.sources.index')
            ->with('success', 'Sync started. It may take a few moments.');
    }

    public function edit(Source $source): View
    {
        return view('admin.sources.edit', [
            'source' => $source,
            'departments' => Department::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Source $source): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'type' => ['required', 'string', Rule::in(['file', 'url'])],
            // Same as store(), except a new file isn't forced on every edit:
            // only required if the source is switching to type=file and has
            // no file_path yet. Re-uploading is still allowed and replaces it.
            'file' => [
                Rule::requiredIf(fn (): bool => $request->input('type') === 'file' && ! $source->file_path),
                'nullable', 'file', 'mimes:xlsx,xls,csv,ods,xlsm,pdf', 'max:20480',
            ],
            'url' => ['required_if:type,url', 'nullable', 'string', 'url', 'max:2048'],
            'ocr' => ['sometimes', 'boolean'],
        ]);

        // Only set if THIS request uploaded a new file - reused across
        // both the "type=file, new upload" and "type switched away from
        // file" cases below, and only cleaned up on failure if it's the
        // one this request created (the source's existing file_path,
        // when kept as-is, must never be touched here).
        $newFilePath = null;

        try {
            if ($validated['type'] === 'file' && $request->hasFile('file')) {
                $newFilePath = $this->storeUploadedFile($request);
            }

            $filePath = match (true) {
                $validated['type'] !== 'file' => null,
                $newFilePath !== null => $newFilePath,
                default => $source->file_path,
            };

            DB::transaction(function () use ($request, $source, $validated, $filePath): void {
                $source->update([
                    'name' => $validated['name'],
                    'department_id' => $validated['department_id'],
                    'type' => $validated['type'],
                    'file_path' => $filePath,
                    'url' => $validated['type'] === 'url' ? $validated['url'] : null,
                    'ocr' => $request->boolean('ocr'),
                ]);
            });
        } catch (Throwable $e) {
            $this->cleanUpAndLogFailure($newFilePath, $e, [
                'action' => 'update',
                'source_id' => $source->id,
                'name' => $validated['name'],
                'department_id' => $validated['department_id'],
                'type' => $validated['type'],
            ]);

            return back()
                ->withInput()
                ->with('error', 'Could not update this source - the uploaded file was not kept. '.$e->getMessage());
        }

        return redirect()
            ->route('admin.sources.index')
            ->with('success', 'Source updated successfully');
    }

    public function destroy(Source $source): RedirectResponse
    {
        $source->delete();

        return redirect()
            ->route('admin.sources.index')
            ->with('success', 'Source deleted successfully');
    }

    /**
     * Move the uploaded file into storage/app/data/ and return its
     * absolute path for storage in Source::file_path.
     */
    private function storeUploadedFile(Request $request): string
    {
        $file = $request->file('file');
        $destination = storage_path('app/data');

        if (! is_dir($destination)) {
            mkdir($destination, recursive: true);
        }

        $filename = $file->hashName();
        $file->move($destination, $filename);

        return $destination.DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * Deletes a just-uploaded file that has no (or no longer has a
     * correct) corresponding Source row, and logs exactly what failed and
     * why - so a failed save is loud in the logs, not a silent orphan.
     *
     * @param  array<string, mixed>  $context
     */
    private function cleanUpAndLogFailure(?string $filePath, Throwable $e, array $context): void
    {
        if ($filePath !== null && file_exists($filePath)) {
            @unlink($filePath);
        }

        Log::channel((string) config('chatbot.log_channel'))->error('Admin source save failed', [
            ...$context,
            'file_path' => $filePath,
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
        ]);
    }
}
