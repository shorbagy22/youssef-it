<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\ValueObjects\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-facing source registration: GET /api/sources lists every
 * configured source, POST /api/sources adds one. sources:sync reads
 * whatever's registered here on its next scheduled run - nothing here
 * touches an Excel file directly, that's SyncSourcesAction's job.
 */
final class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Source::all());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department' => ['required', 'string', Rule::enum(Department::class)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['file', 'url'])],
            'file_path' => ['required_if:type,file', 'nullable', 'string', 'max:2048'],
            'url' => ['required_if:type,url', 'nullable', 'string', 'url', 'max:2048'],
        ]);

        $source = Source::query()->create($validated);

        return response()->json($source, 201);
    }
}
