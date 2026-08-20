<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AIResponseInvalidException;
use App\Exceptions\AIServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\DataRecord;
use App\Models\Source;
use App\Services\DataAnalysisService;
use App\Services\OllamaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/data/analyze - the strictest and most general-purpose of
 * this app's four AI-backed endpoints (see DataAnalysisService's
 * docblock for how it compares to ChatController, DefectAnalysisController,
 * and DataReadabilityController). Detects corruption FIRST, returning a
 * "status": "corrupted" shape and stopping if the data doesn't look
 * reliably readable; otherwise performs full structure detection and,
 * if `message` was given, answers it - all in the single structured
 * JSON reply DataAnalysisService's system prompt defines, returned to
 * the caller as-is (same raw-passthrough shape as DefectAnalysisController,
 * not ChatController's {"answer": "..."}).
 *
 * `message` is optional and, unlike ChatController's, is NOT used to
 * filter/rank which rows get sent - see DataAnalysisService's docblock
 * for why relevance filtering is the wrong tool for structure/corruption
 * detection. It only changes what the AI is asked to do with the same
 * fixed row set: analyze only, or analyze and also answer this question.
 *
 * `source_id` and MAX_ROWS follow the same reasoning as
 * DataReadabilityController - see that class's docblock.
 */
final class DataAnalysisController extends Controller
{
    private const int MAX_ROWS = 300;

    public function __construct(
        private readonly DataAnalysisService $dataAnalysisService,
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
            'message' => ['nullable', 'string', 'max:2000'],
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

        $prompt = $this->dataAnalysisService->buildPrompt($records, $validated['message'] ?? null);

        try {
            $answer = $this->ollama->generate($prompt, jsonMode: true);
        } catch (AIServiceUnavailableException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 503);
        }

        try {
            $result = $this->dataAnalysisService->parseResponse($answer);
        } catch (AIResponseInvalidException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json($result);
    }
}
