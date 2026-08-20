<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AIServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\DataRecord;
use App\Models\Source;
use App\Services\OllamaClient;
use App\Services\PdfQaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/pdf/ask - exact-extraction Q&A against ONE specific PDF
 * source's text (see PdfQaService's docblock for how this differs from
 * this app's other four AI endpoints). `source_id` is required, not
 * optional like DataReadabilityController's/DataAnalysisController's -
 * this endpoint has no "whole department" mode, since the underlying
 * prompt explicitly forbids "Excel-style reasoning" and is built around
 * searching ONE document's text. A source_id that isn't actually a PDF
 * (by extension - same detection SyncSourcesAction itself uses) is
 * rejected outright rather than silently treated as one.
 *
 * MAX_ROWS is far more generous than the other endpoints' 300 - this
 * is one PDF's full extracted text, not a slice of a potentially huge
 * department, and the whole point is searching the complete document
 * rather than a capped sample of it.
 */
final class PdfQaController extends Controller
{
    private const int MAX_ROWS = 5000;

    public function __construct(
        private readonly PdfQaService $pdfQaService,
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

        $source = Source::query()->find($validated['source_id']);

        if (! $this->isPdfSource($source)) {
            return response()->json([
                'error' => "Source #{$source->id} is not a PDF source.",
            ], 422);
        }

        $records = DataRecord::query()
            ->where('source_id', $source->id)
            ->orderBy('row_index')
            ->limit(self::MAX_ROWS)
            ->get();

        $prompt = $this->pdfQaService->buildPrompt($records, $validated['message']);

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
     * Same extension-based detection SyncSourcesAction itself uses (see
     * that class's isPdf()) - a url-type source has no local file_path
     * to check, so its own URL's path extension is used instead, same
     * as SyncSourcesAction::urlExtension().
     */
    private function isPdfSource(Source $source): bool
    {
        $path = $source->type === 'file' ? $source->file_path : $source->url;

        if ($path === null) {
            return false;
        }

        return strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION)) === 'pdf';
    }
}
