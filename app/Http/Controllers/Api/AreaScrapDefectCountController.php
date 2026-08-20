<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AIServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\DataRecord;
use App\Models\Source;
use App\Services\AreaScrapDefectCountService;
use App\Services\ChatDataService;
use App\Services\OllamaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * POST /api/defects/area-scrap-count - a deliberately narrow endpoint
 * for ONE known file/sheet's fixed column layout (see
 * AreaScrapDefectCountService's docblock for the exact mapping and why
 * fixed columns are safe here specifically, unlike everywhere else in
 * this app).
 *
 * `source_id` is required, same as PdfQaController, and is validated
 * the same way that one validates "is this actually a PDF": here,
 * rejected outright unless the source actually HAS a "Total" sheet -
 * the fixed column mapping this whole endpoint depends on is only ever
 * true for that one sheet, so a source without it can't produce a
 * meaningful answer through this endpoint regardless of what the AI is
 * told, and shouldn't silently pretend to.
 *
 * Row fetching reuses ChatDataService's SQL search methods (see
 * DefectQueryController for the full rationale, including a real,
 * confirmed bug found live against this app's actual data: a KEYWORD
 * match like "assembly" - which matches every row in that area
 * regardless of date - was being treated as equally "confirmed" as an
 * exact DATE match, causing the AI to confidently report a different
 * month's defects as if they answered a specific day's question. Only
 * ChatDataService::findByDate() counts as confirmed here; a keyword-only
 * match never does), scoped to this one source via $sourceId, then
 * narrowed further to sheet_name="Total" - a source could have OTHER
 * sheets whose rows happen to also match but whose columns mean
 * something completely different, which the fixed mapping would
 * silently misread. A date match on some OTHER sheet in the same
 * source is still a confirmed negative for THIS endpoint's
 * fixed-column, Total-sheet-only scope.
 */
final class AreaScrapDefectCountController extends Controller
{
    private const string REQUIRED_SHEET = 'Total';

    private const int FALLBACK_ROWS = 300;

    private const string NO_MATCH_ANSWER = 'No defects found for this date';

    public function __construct(
        private readonly ChatDataService $chatDataService,
        private readonly AreaScrapDefectCountService $areaScrapDefectCountService,
        private readonly OllamaClient $ollama,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // See ChatController for why this is necessary: PHP's own
        // max_execution_time kills the request before OllamaClient's own
        // configured HTTP timeout ever gets a chance to.
        set_time_limit(((int) config('ollama.timeout') * 2) + 30);

        $validated = $request->validate([
            'source_id' => ['required', 'integer', Rule::exists('sources', 'id')],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $source = Source::query()->with('department')->find($validated['source_id']);

        $hasTotalSheet = DataRecord::query()
            ->where('source_id', $source->id)
            ->where('sheet_name', self::REQUIRED_SHEET)
            ->exists();

        if (! $hasTotalSheet) {
            return response()->json([
                'error' => "Source #{$source->id} has no \"".self::REQUIRED_SHEET.'" sheet - this endpoint\'s fixed column mapping only applies to that specific layout.',
            ], 422);
        }

        $department = $source->department->slug;

        [$records, $confirmedMatch] = $this->resolveRecords($department, $source->id, $validated['message']);

        if ($records === null) {
            return response()->json(['answer' => self::NO_MATCH_ANSWER]);
        }

        $prompt = $this->areaScrapDefectCountService->buildPrompt($records, $validated['message'], $confirmedMatch);

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
    private function resolveRecords(string $department, int $sourceId, string $message): array
    {
        $dateMatch = $this->chatDataService
            ->findByDate($department, $message, $sourceId)
            ?->where('sheet_name', self::REQUIRED_SHEET)
            ->values();

        if ($dateMatch !== null && $dateMatch->isNotEmpty()) {
            return [$dateMatch, true];
        }

        if ($this->chatDataService->hasDateSignal($message)) {
            // A specific date WAS asked about, and the precise SQL
            // search (scoped to this source's Total sheet) found
            // nothing for it - a confirmed negative, full stop. See
            // class docblock for why a keyword fallback must not run
            // here instead.
            return [null, false];
        }

        $found = $this->chatDataService->findRelevantRecords($department, $message, $sourceId);
        $records = $found?->where('sheet_name', self::REQUIRED_SHEET)->values();

        if ($records !== null && $records->isNotEmpty()) {
            // Only ever a keyword match at this point - relevant-ish,
            // never "confirmed" the way a date match is.
            return [$records, false];
        }

        $records = DataRecord::query()
            ->where('source_id', $sourceId)
            ->where('sheet_name', self::REQUIRED_SHEET)
            ->orderBy('row_index')
            ->limit(self::FALLBACK_ROWS)
            ->get();

        return [$records, false];
    }
}
