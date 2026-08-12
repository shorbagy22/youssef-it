<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AIServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    private readonly string $baseUrl;

    private readonly string $model;

    private readonly int $timeout;

    private readonly int $connectTimeout;

    private readonly int $attempts;

    private readonly int $retryDelayMs;

    private readonly bool $think;

    private readonly string $proxy;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ollama.base_url'), '/');
        $this->model = (string) config('ollama.model');
        $this->timeout = (int) config('ollama.timeout');
        $this->connectTimeout = (int) config('ollama.connect_timeout');
        $this->attempts = max(1, (int) config('ollama.attempts'));
        $this->retryDelayMs = max(0, (int) config('ollama.retry_delay_ms'));
        $this->think = (bool) config('ollama.think');
        $this->proxy = (string) config('ollama.proxy', '');
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
                ->acceptJson()
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withOptions(['proxy' => $this->proxy])
                ->retry(
                    $this->attempts,
                    $this->retryDelayMs,
                    fn (Throwable $exception): bool => $this->shouldRetry($exception),
                )
                ->post('/api/generate', [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'stream' => false,
                    'think' => $this->think,
                ]);
        } catch (ConnectionException|RequestException $e) {
            $log->error('Ollama request failed', ['exception' => $e->getMessage()]);

            throw new AIServiceUnavailableException('Could not reach Ollama.', previous: $e);
        }

        // Ollama's own field name is "response", not "answer" - the
        // /api/chat endpoint reads this and re-wraps it as "answer" in
        // its own JSON response to callers.
        $answer = $response->json('response');

        if (! is_string($answer) || trim($answer) === '') {
            $log->error('Ollama returned an unexpected response shape', [
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'body_length' => strlen($response->body()),
            ]);

            throw new AIServiceUnavailableException('Ollama returned an unexpected response shape.');
        }

        $answer = trim($answer);

        $log->info('Ollama generate succeeded', [
            'model' => $this->model,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'answer_length' => strlen($answer),
        ]);

        return $answer;
    }

    /**
     * Retry only failures that can realistically succeed on another attempt.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }
}
