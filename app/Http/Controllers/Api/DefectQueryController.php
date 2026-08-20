<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AIServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\DataRecord;
use App\Services\ChatDataService;
use App\Services\DefectQueryService;
use App\Services\OllamaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * POST /api/defects/query - "how many of each defect on this date/in
 * this area", grouped and summed, in a fixed short-answer-plus-table
 * shape (see DefectQueryService for how this differs from
 * /api/defects/analyze and /api/chat).
 *
 * Row fetching deliberately REUSES ChatDataService's SQL search methods
 * rather than repeating its own date/keyword logic - see that class for
 * the real, confirmed bug its department-wide SQL search fixed (a
 * recency-limited pool one large source could crowd out entirely).
 *
 * Row fetching is a FOUR-way branch. This grew from three after a
 * second real, confirmed bug was found live against this app's actual
 * data: treating ANY match (date OR keyword) as "confirmed" caused the
 * AI to be told rows matched from a KEYWORD search (e.g. "assembly",
 * which matches every row in that area regardless of date) were
 * confirmed for a SPECIFIC date question - it then confidently reported
 * defects from entirely different months as if they answered a
 * particular day's question, because it had been told to trust data
 * that was never actually verified against the date asked. A keyword
 * match and a date match are not the same strength of evidence for a
 * date-specific question, and must not be treated as interchangeable:
 *
 * 1. findByDate() found rows → a CONFIRMED match (exact, SQL-verified
 *    against the specific date asked). Told to the AI as fact.
 * 2. The question named a specific date, but findByDate() found ZERO
 *    rows → a CONFIRMED negative, determined precisely by SQL. The AI
 *    is never even called - there's nothing left for it to decide.
 * 3. The question had no date at all, but DOES have a keyword signal →
 *    genuinely open-ended (no specific date to verify against), falls
 *    back to ChatDataService::findRelevantRecords()'s keyword search for
 *    a relevant-ish sample, WITHOUT claiming confirmation - the AI must
 *    still exercise its own judgment here, since nothing was verified.
 * 4. No date, no keyword signal at all → a broad natural-order sample,
 *    same as before.
 */
final class DefectQueryController extends Controller
{
    private const int FALLBACK_ROWS = 300;

    private const string NO_MATCH_ANSWER = 'No defects found for the specified date and area.';

    public function __construct(
        private readonly ChatDataService $chatDataService,
        private readonly DefectQueryService $defectQueryService,
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
            'message' => ['required', 'string', 'max:2000'],
        ]);

        [$records, $confirmedMatch] = $this->resolveRecords($validated['department'], $validated['message']);

        if ($records === null) {
            return response()->json(['answer' => self::NO_MATCH_ANSWER]);
        }

        $prompt = $this->defectQueryService->buildPrompt($records, $validated['message'], $confirmedMatch);

        try {
            $answer = $this->ollama->generate($prompt);
        } catch (AIServiceUnavailableException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 503);
        }

        return response()->json(['answer' => $answer]);
    }

    /**
     * @return array{0: Collection<int, DataRecord>|null, 1: bool}
     */
    private function resolveRecords(string $department, string $message): array
    {
        $dateMatch = $this->chatDataService->findByDate($department, $message);

        if ($dateMatch !== null) {
            return [$dateMatch, true];
        }

        if ($this->chatDataService->hasDateSignal($message)) {
            // A specific date WAS asked about, and the precise SQL
            // search found nothing for it - a confirmed negative, full
            // stop. Falling through to a keyword match here is exactly
            // the bug this class docblock describes: a broad match on
            // "assembly" is not evidence about June 25th specifically.
            return [null, false];
        }

        $records = $this->chatDataService->findRelevantRecords($department, $message);

        if ($records !== null) {
            // Only ever a keyword match at this point (no date was
            // asked about) - genuinely relevant-ish, but never
            // "confirmed" the way a date match is.
            return [$records, false];
        }

        $records = DataRecord::query()
            ->where('department', $department)
            ->orderBy('sheet_index')
            ->orderBy('row_index')
            ->limit(self::FALLBACK_ROWS)
            ->get();

        return [$records, false];
    }
}
