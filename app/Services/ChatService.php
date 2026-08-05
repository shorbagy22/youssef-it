<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LLMClient;
use App\DTOs\ChatRequest;
use App\DTOs\ChatResponse;
use App\Exceptions\OllamaUnavailableException;

/**
 * Turns a ChatRequest into a ChatResponse: business logic only, no HTTP,
 * no controllers. Depends on the LLMClient contract rather than
 * OllamaClient directly, so it never needs to know it's Ollama on the
 * other end.
 *
 * Deliberately separate from ChatAction so this logic is reusable from
 * any future entry point (a queued job, an artisan command) without
 * going through the Action/Controller/HTTP layers.
 */
final class ChatService
{
    public function __construct(
        private readonly LLMClient $llmClient,
        private readonly PromptBuilder $promptBuilder,
    ) {}

    /**
     * @throws OllamaUnavailableException if the model server is unreachable
     *                                    or fails to respond successfully. Left uncaught here since
     *                                    it's already a domain-level exception - the HTTP layer
     *                                    (ChatController) decides what status code that becomes.
     */
    public function handle(ChatRequest $request): ChatResponse
    {
        $systemPrompt = $this->promptBuilder->buildSystemPrompt();
        $userPrompt = $this->promptBuilder->buildUserPrompt($request->message, $request->history);

        $answer = $this->llmClient->generate($systemPrompt, $userPrompt);

        return new ChatResponse(answer: $answer);
    }
}
