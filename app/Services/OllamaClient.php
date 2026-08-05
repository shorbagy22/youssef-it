<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LLMClient;
use App\Exceptions\OllamaUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to a local Ollama server over HTTP. The only class in the
 * application aware of Ollama's request/response format.
 *
 * Uses Laravel's Http client exclusively (no shelling out to the `ollama`
 * CLI, no SDK). See docs/ollama-api.md for the exact endpoints and
 * payload shapes this class relies on.
 */
final class OllamaClient implements LLMClient
{
    private readonly string $baseUrl;

    private readonly string $model;

    private readonly int $timeout;

    private readonly int $retries;

    private readonly int $retryDelayMs;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ollama.base_url'), '/');
        $this->model = (string) config('ollama.model');
        $this->timeout = (int) config('ollama.timeout');
        $this->retries = (int) config('ollama.retries');
        $this->retryDelayMs = (int) config('ollama.retry_delay_ms');
    }

    /**
     * Send a single generation request to POST /api/generate.
     *
     * Non-streaming: the full answer is returned in one response body
     * rather than as incremental chunks. Retries (via Laravel's Http
     * client) cover transient connection failures and bad status codes -
     * ->retry() throws once attempts are exhausted, so anything that
     * still fails - a refused connection, a non-2xx response, or an
     * unexpected response shape - becomes an OllamaUnavailableException.
     */
    public function generate(string $systemPrompt, string $userPrompt): string
    {
        $log = Log::channel((string) config('chatbot.log_channel'));

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelayMs)
                ->post('/api/generate', [
                    'model' => $this->model,
                    'system' => $systemPrompt,
                    'prompt' => $userPrompt,
                    'stream' => false,
                ]);
        } catch (ConnectionException|RequestException $e) {
            $log->error('Ollama request failed', ['exception' => $e->getMessage()]);

            throw new OllamaUnavailableException('Could not reach Ollama.', previous: $e);
        }

        $answer = $response->json('response');

        if (! is_string($answer)) {
            $log->error('Ollama response missing a string "response" field', [
                'body' => $response->body(),
            ]);

            throw new OllamaUnavailableException('Ollama returned an unexpected response shape.');
        }

        $log->info('Ollama generate succeeded', [
            'model' => $this->model,
            'answer_length' => strlen($answer),
        ]);

        return $answer;
    }

    /**
     * Whether the Ollama server is reachable, via a lightweight GET to
     * its root - Ollama responds "Ollama is running" with no model load
     * required, making this cheap enough to call before every request if
     * needed.
     */
    public function isHealthy(): bool
    {
        try {
            $response = Http::baseUrl($this->baseUrl)->timeout(5)->get('/');
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful();
    }
}
