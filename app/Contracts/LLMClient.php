<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\AIServiceUnavailableException;

/**
 * Behavior contract for a large language model client.
 *
 * ChatService depends on this interface, never on the concrete
 * AIClient - the Dependency Inversion seam that lets the underlying
 * LLM be swapped (or mocked in tests) without touching business logic.
 * Bound to AIClient in AppServiceProvider.
 */
interface LLMClient
{
    /**
     * Generate a completion for the given prompt.
     *
     * @throws AIServiceUnavailableException if the AI service cannot be
     *                                       reached or fails to respond successfully after retries.
     */
    public function generate(string $systemPrompt, string $userPrompt): string;

    /**
     * Whether the AI service is currently reachable.
     */
    public function isHealthy(): bool;
}
