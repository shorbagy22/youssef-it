<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AIServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\DataRecord;
use App\Services\ChatDataService;
use App\Services\OllamaClient;
use App\ValueObjects\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/chat - answers a department-scoped question using that
 * department's most recent structured data records.
 *
 * Kept thin per Clean Architecture: validates input, fetches the latest
 * records, delegates prompt-building to ChatDataService and the actual
 * generation to OllamaClient, translates a failure into HTTP 503. No
 * business logic lives here.
 *
 * This is a separate integration from the web /chat pipeline (ChatAction/
 * ChatService/AIClient), which talks to a different, IT-owned AI API
 * wrapper. This endpoint calls Ollama directly.
 */
final class ChatController extends Controller
{
    public function __construct(
        private readonly ChatDataService $chatDataService,
        private readonly OllamaClient $ollama,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department' => ['required', 'string', Rule::enum(Department::class)],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $records = DataRecord::query()
            ->where('department', $validated['department'])
            ->orderByDesc('date')
            ->limit(10)
            ->get();

        $prompt = $this->chatDataService->buildPrompt($records, $validated['message']);

        try {
            $answer = $this->ollama->generate($prompt);
        } catch (AIServiceUnavailableException) {
            return response()->json([
                'error' => 'The AI service is currently unavailable. Please try again shortly.',
            ], 503);
        }

        return response()->json(['answer' => $answer]);
    }
}
