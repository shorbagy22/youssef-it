<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ChatAction;
use App\DTOs\ChatRequest;
use App\Exceptions\OllamaUnavailableException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Renders the chat page and handles sending messages to it.
 *
 * Kept thin per Clean Architecture: send() only validates input, builds a
 * ChatRequest, calls ChatAction, and translates the result (or an
 * OllamaUnavailableException) into a JSON response - no business logic
 * lives here.
 */
final class ChatController extends Controller
{
    public function __construct(
        private readonly ChatAction $chatAction,
    ) {}

    /**
     * Display the chat page.
     */
    public function index(): View
    {
        return view('chat.index');
    }

    /**
     * Answer a chat message.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'history' => ['sometimes', 'array'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        $chatRequest = new ChatRequest(
            message: $validated['message'],
            history: $validated['history'] ?? [],
        );

        try {
            $response = $this->chatAction->handle($chatRequest);
        } catch (OllamaUnavailableException) {
            return response()->json([
                'error' => 'Ollama is currently unavailable. Please try again shortly.',
            ], 503);
        }

        return response()->json([
            'answer' => $response->answer,
        ]);
    }
}
