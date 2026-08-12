<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Source;
use App\ValueObjects\Department as LegacyDepartment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-facing source registration: GET /api/sources lists every
 * configured source, POST /api/sources adds one. sources:sync reads
 * whatever's registered here on its next scheduled run - nothing here
 * touches an Excel file directly, that's SyncSourcesAction's job.
 *
 * The request/response contract is unchanged from before Sources moved
 * to a department_id FK: callers still send `department` as one of the
 * four fixed slugs (kept in lockstep with the seeded departments table -
 * see that migration), it's just resolved to a department_id internally.
 */
final class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Source::query()->with('department')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department' => ['required', 'string', Rule::enum(LegacyDepartment::class)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['file', 'url'])],
            'file_path' => ['required_if:type,file', 'nullable', 'string', 'max:2048'],
            'url' => ['required_if:type,url', 'nullable', 'string', 'url', 'max:2048'],
        ]);

        Source::query()->create([
            'department_id' => Department::where('slug', $validated['department'])->firstOrFail()->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'file_path' => $validated['file_path'] ?? null,
            'url' => $validated['url'] ?? null,
        ]);

        return response()->json(['message' => 'Source created'], 201);
    }
}
