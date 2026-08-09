<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AIServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to Ollama's raw HTTP API directly - no wrapper service in
 * between. Used exclusively by the /api/chat data-pipeline endpoint,
 * which is a separate integration from the older web /chat pipeline's
 * AIClient (that one calls a different, IT-owned AI API wrapper on a
 * different port). The only class that knows Ollama's request/response
 * format for this pipeline.
 */
final class OllamaClient
{
    private const int RETRIES = 2;

    private const int RETRY_DELAY_MS = 200;

    private readonly string $baseUrl;

    private readonly string $model;

    private readonly int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ollama.base_url'), '/');
        $this->model = (string) config('ollama.model');
        $this->timeout = (int) config('ollama.timeout');
    }

    /**
     * Send a single generation request to POST /api/generate and return
     * the answer text.
     *
     * @throws AIServiceUnavailableException if Ollama is unreachable, or
     *                                       fails to respond successfully after retries.
     */
    public function generate(string $prompt): string
    {
        $log = Log::channel((string) config('chatbot.log_channel'));
        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->retry(self::RETRIES, self::RETRY_DELAY_MS)
                ->post('/api/generate', [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'stream' => false,
                ]);
        } catch (ConnectionException|RequestException $e) {
            $log->error('Ollama request failed', ['exception' => $e->getMessage()]);

            throw new AIServiceUnavailableException('Could not reach Ollama.', previous: $e);
        }

        // Ollama's own field name is "response", not "answer" - the
        // /api/chat endpoint reads this and re-wraps it as "answer" in
        // its own JSON response to callers.
        $answer = $response->json('response');

        if (! is_string($answer)) {
            $log->error('Ollama returned an unexpected response shape', [
                'body' => $response->body(),
            ]);

            throw new AIServiceUnavailableException('Ollama returned an unexpected response shape.');
        }

        $log->info('Ollama generate succeeded', [
            'model' => $this->model,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'answer_length' => strlen($answer),
        ]);

        return $answer;
    }
}
