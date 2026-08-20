<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AIResponseInvalidException;
use App\Exceptions\AIServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\DataRecord;
use App\Services\DefectAnalysisService;
use App\Services\OllamaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/defects/analyze - a fixed-question counterpart to
 * /api/chat: instead of answering an arbitrary free-text question in
 * prose, this always asks "find and summarize defects in this data" and
 * always returns the structured JSON contract DefectAnalysisService's
 * system prompt defines (detected_tables/defects/... or the no-data
 * error shape), not an {"answer": "..."} string.
 *
 * Kept thin per Clean Architecture, same as ChatController: validates
 * input, fetches the rows, delegates prompt-building and response
 * parsing to DefectAnalysisService, generation to OllamaClient.
 *
 * MAX_ROWS is a direct send cap, not a "pool then filter down" scheme
 * like ChatController's - there's no question here to rank rows by
 * relevance against (see DefectAnalysisService's docblock for why
 * ChatDataService's relevance filtering isn't reused). A defect-summary
 * table is realistically dozens to a few hundred rows even across
 * several sheets, so this stays deliberately smaller than /api/chat's
 * 5,000-row candidate pool - a department whose rows genuinely don't
 * fit will get a truncated, most-recent-first view rather than a
 * request that never completes.
 *
 * `department` is validated against the admin-managed departments table,
 * same as ChatController.
 */
final class DefectAnalysisController extends Controller
{
    private const int MAX_ROWS = 300;

    public function __construct(
        private readonly DefectAnalysisService $defectAnalysisService,
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
        ]);

        // Ascending sheet/row order, not recency - table and header
        // detection needs a sheet's rows in the order they actually
        // appear, not the most-recently-synced-first order ChatController
        // uses for its own, different purpose (recency as a relevance
        // proxy for a question with no other signal).
        $records = DataRecord::query()
            ->where('department', $validated['department'])
            ->orderBy('sheet_index')
            ->orderBy('row_index')
            ->limit(self::MAX_ROWS)
            ->get();

        $prompt = $this->defectAnalysisService->buildPrompt($records);

        try {
            $answer = $this->ollama->generate($prompt, jsonMode: true);
        } catch (AIServiceUnavailableException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 503);
        }

        try {
            $result = $this->defectAnalysisService->parseResponse($answer);
        } catch (AIResponseInvalidException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json($result);
    }
}
