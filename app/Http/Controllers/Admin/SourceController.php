<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Source;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Authenticated admin UI for managing Sources - a Blade counterpart to
 * Api\SourceController's POST /api/sources, not a replacement for it.
 * Uploaded files are stored under storage/app/data/ and referenced by
 * absolute path in file_path, matching how SyncSourcesAction reads
 * file-type sources directly off disk (no Storage disk abstraction).
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
            'file' => ['required_if:type,file', 'nullable', 'file', 'mimes:xlsx,xls', 'max:20480'],
            'url' => ['required_if:type,url', 'nullable', 'string', 'url', 'max:2048'],
        ]);

        Source::query()->create([
            'name' => $validated['name'],
            'department_id' => $validated['department_id'],
            'type' => $validated['type'],
            'file_path' => $validated['type'] === 'file' ? $this->storeUploadedFile($request) : null,
            'url' => $validated['type'] === 'url' ? $validated['url'] : null,
        ]);

        return redirect()
            ->route('admin.sources.index')
            ->with('status', 'Source created.');
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
}
