<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AIServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\DataRecord;
use App\Services\ChatDataService;
use App\Services\OllamaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/chat - answers a department-scoped question using that
 * department's synced rows (each already captured raw by
 * SyncSourcesAction - see DataRecord, one row per Excel row).
 *
 * Kept thin per Clean Architecture: validates input, fetches the rows,
 * delegates prompt-building to ChatDataService and the actual generation
 * to OllamaClient, translates a failure into HTTP 503. No business logic
 * lives here.
 *
 * Row fetching is a FOUR-way branch, matching DefectQueryController's
 * (see that class's docblock for the full rationale - the same real,
 * live-confirmed bugs this fixes here too, since this is the endpoint
 * the actual chat UI calls):
 *
 * 1. The question names a specific date, and findByDate() found rows for
 *    it → a CONFIRMED match. The AI is NOT called at all for this case -
 *    ChatDataService::formatRawRows() returns the matching rows
 *    literally, tab-separated, exactly as stored. This is a second real,
 *    confirmed fix beyond "tell the AI it's confirmed": even WITH that
 *    confirmation, asking the AI to describe/summarize the rows in
 *    prose was still an extra step where it could paraphrase a defect
 *    name, garble which column means what, or drop a row - observed
 *    live against this app's real data. When PHP already knows the
 *    exact matching rows with certainty, the raw data itself IS the
 *    correct answer; the AI's retelling of it is a strictly worse,
 *    strictly slower version of the same information.
 * 2. The question names a date, but findByDate() found ZERO rows for it
 *    → a CONFIRMED negative, determined precisely by SQL. The AI is
 *    never even called - there's nothing left for it to decide, and
 *    falling through to a keyword/recency sample here was the OTHER real
 *    bug: a broad match (e.g. on an area name) or a recency-ordered
 *    sample would get shown as if it were relevant to a date it has
 *    nothing to do with.
 * 3. No date, but a keyword signal exists → ChatDataService::findRelevantRecords()'s
 *    keyword search, genuinely open-ended (no date to verify against),
 *    the AI reasons freely - never marked as confirmed.
 * 4. No date, no keyword signal at all → CANDIDATE_POOL_LIMIT's plain
 *    recency-ordered fetch, same as before - a pragmatic bound for "how
 *    should the AI see a huge dataset with no matching signal at all"
 *    (summarization/aggregation would be the real answer for that, a
 *    separate feature).
 *
 * `department` is validated against the admin-managed departments table
 * (not the old fixed ValueObjects\Department enum) so a department added
 * via /admin/departments is immediately askable here too, once it has a
 * synced source - there's no longer a fixed four-department ceiling on
 * this endpoint.
 *
 * This is a separate integration from the web /chat pipeline (ChatAction/
 * ChatService/AIClient), which talks to a different, IT-owned AI API
 * wrapper. This endpoint calls Ollama directly.
 */
final class ChatController extends Controller
{
    private const int CANDIDATE_POOL_LIMIT = 5000;

    private const string NO_MATCH_ANSWER = 'No data found for that specific date in the provided records.';

    public function __construct(
        private readonly ChatDataService $chatDataService,
        private readonly OllamaClient $ollama,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // PHP's own max_execution_time (php.ini) kills the whole request
        // at 30s by default, regardless of OllamaClient's own configured
        // HTTP timeout - it fires first, before Guzzle's timeout logic
        // ever gets a chance to produce a catchable exception, and a
        // hard PHP timeout can't be caught (same class of problem as the
        // memory-exhaustion crashes in the sync pipeline - see
        // RawRowsImport). Setting it here, keyed to the actual configured
        // Ollama timeout plus headroom for the client's own retries,
        // means the two can't silently drift out of sync again the way
        // they did when php.ini's 30s default was left as-is.
        set_time_limit(((int) config('ollama.timeout') * 2) + 30);

        $validated = $request->validate([
            'department' => ['required', 'string', Rule::exists('departments', 'slug')],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $dateMatch = $this->chatDataService->findByDate($validated['department'], $validated['message']);

        if ($dateMatch !== null) {
            // A precise, SQL-verified match - return the actual
            // matching rows directly, exactly as stored, rather than
            // asking the AI to transcribe/summarize them. See class
            // docblock.
            return response()->json([
                'answer' => $this->chatDataService->formatRawRows($dateMatch, $validated['message']),
            ]);
        }

        if ($this->chatDataService->hasDateSignal($validated['message'])) {
            // A specific date WAS asked about, and the precise SQL
            // search found nothing for it - a confirmed negative, not
            // "let's ask the AI to look again" (which is exactly how a
            // broad keyword or recency sample used to get shown as if
            // it answered a date it has nothing to do with).
            return response()->json(['answer' => self::NO_MATCH_ANSWER]);
        }

        $records = $this->chatDataService->findRelevantRecords($validated['department'], $validated['message']);

        if ($records === null) {
            $records = DataRecord::query()
                ->where('department', $validated['department'])
                ->orderByDesc('id')
                ->limit(self::CANDIDATE_POOL_LIMIT)
                ->get();
        }

        $prompt = $this->chatDataService->buildPrompt($records, $validated['message']);

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
