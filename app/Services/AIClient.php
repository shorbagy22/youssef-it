<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LLMClient;
use App\Exceptions\AIServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the company's centralized AI HTTP endpoint over Laravel's Http
 * client. IT owns everything behind this one endpoint - SharePoint
 * access, Excel sync, data ingestion, and the AI model itself (including
 * Ollama) - so this is the only external service Laravel's chat feature
 * depends on, and the only class that knows this endpoint's request and
 * response shape.
 *
 * Request/response field names ("question"/"system" in, "answer" out)
 * are this app's own convention pending IT's published API contract -
 * see docs/ai-client.md for exactly what to adjust if theirs differs.
 */
final class AIClient implements LLMClient
{
    private readonly string $baseUrl;

    private readonly int $timeout;

    private readonly int $retries;

    private readonly int $retryDelayMs;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ai.base_url'), '/');
        $this->timeout = (int) config('ai.timeout');
        $this->retries = (int) config('ai.retries');
        $this->retryDelayMs = (int) config('ai.retry_delay_ms');
    }

    /**
     * Send the system and user prompts to the AI endpoint and return its
     * answer. Retries (via Laravel's Http client) cover transient
     * connection failures and bad status codes - ->retry() throws once
     * attempts are exhausted, so anything that still fails becomes an
     * AIServiceUnavailableException.
     */
    public function generate(string $systemPrompt, string $userPrompt): string
    {
        $log = Log::channel((string) config('chatbot.log_channel'));
        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelayMs)
                ->post('', [
                    'question' => $userPrompt,
                    'system' => $systemPrompt,
                ]);
        } catch (ConnectionException|RequestException $e) {
            $log->error('AI service request failed', ['exception' => $e->getMessage()]);

            throw new AIServiceUnavailableException('Could not reach the AI service.', previous: $e);
        }

        $answer = $response->json('answer');

        if (! is_string($answer)) {
            $log->error('AI service returned an unexpected response shape', [
                'body' => $response->body(),
            ]);

            throw new AIServiceUnavailableException('The AI service returned an unexpected response shape.');
        }

        $log->info('AI service generate succeeded', [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'answer_length' => strlen($answer),
        ]);

        return $answer;
    }

    /**
     * Whether the AI service is reachable, via a lightweight GET to
     * {base_url}/health. If that route doesn't exist, this simply
     * returns false (a 404 is not "successful") rather than throwing -
     * the endpoint isn't guaranteed to expose a health check.
     */
    public function isHealthy(): bool
    {
        try {
            $response = Http::baseUrl($this->baseUrl)->timeout(5)->get('/health');
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful();
    }
}
