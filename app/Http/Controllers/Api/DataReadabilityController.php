<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AIServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\DataRecord;
use App\Models\Source;
use App\Services\DataReadabilityService;
use App\Services\OllamaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/data/check-readability - a strict "is this data even
 * readable" gate, separate from both /api/chat (answers a question) and
 * /api/defects/analyze (always returns a fixed defect JSON schema). No
 * output contract is enforced here - DataReadabilityService's system
 * prompt already specifies the exact strings to return for the
 * corrupted/no-structure cases, so the AI's reply is passed back as-is
 * under {"answer": "..."}, the same shape ChatController uses.
 *
 * `source_id` is optional and, when given, narrows the check to just
 * that one source rather than every source in the department - the
 * primary intended use is checking a single just-synced source (most
 * often a PDF, this app's most likely source of genuinely garbled text -
 * see DataReadabilityService's docblock) right after syncing it, not a
 * whole department's mixed data at once. It's validated to actually
 * belong to the given department, since a caller only ever authorizes
 * against `department` - without this check, a source_id from a
 * different department could be used to read that department's data
 * through this endpoint.
 *
 * MAX_ROWS mirrors DefectAnalysisController's - see that class's
 * docblock for why this is a direct send cap, not a "pool then filter"
 * scheme like ChatController's.
 */
final class DataReadabilityController extends Controller
{
    private const int MAX_ROWS = 300;

    public function __construct(
        private readonly DataReadabilityService $dataReadabilityService,
        private readonly OllamaClient $ollama,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // See ChatController for why this is necessary: PHP's own
        // max_execution_time kills the request before OllamaClient's own
        // configured HTTP timeout ever gets a chance to.
        set_time_limit(((int) config('ollama.timeout') * 2) + 30);

        $validated = $request->validate([
            'department' => ['required', 'string', Rule::exists('departments', 'slug')],
            'source_id' => ['nullable', 'integer', Rule::exists('sources', 'id')],
        ]);

        if (isset($validated['source_id'])) {
            $source = Source::query()->with('department')->find($validated['source_id']);

            if ($source->department->slug !== $validated['department']) {
                return response()->json([
                    'error' => 'source_id does not belong to the given department.',
                ], 422);
            }
        }

        $query = DataRecord::query()->where('department', $validated['department']);

        if (isset($validated['source_id'])) {
            $query->where('source_id', $validated['source_id']);
        }

        $records = $query
            ->orderBy('sheet_index')
            ->orderBy('row_index')
            ->limit(self::MAX_ROWS)
            ->get();

        $prompt = $this->dataReadabilityService->buildPrompt($records);

        try {
            $answer = $this->ollama->generate($prompt);
        } catch (AIServiceUnavailableException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 503);
        }

        return response()->json(['answer' => $answer]);
    }
}
