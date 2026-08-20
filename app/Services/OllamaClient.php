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

    private readonly int $contextWindow;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ollama.base_url'), '/');
        $this->model = (string) config('ollama.model');
        $this->timeout = (int) config('ollama.timeout');
        $this->contextWindow = (int) config('ollama.context_window');
    }

    /**
     * Send a single generation request to POST /api/generate and return
     * the answer text.
     *
     * $jsonMode sets Ollama's own "format": "json" request field, which
     * constrains the model's output to always be syntactically valid
     * JSON at the token-sampling level - a stronger guarantee than
     * asking for JSON in the prompt text alone, which a model can still
     * ignore (wrapping it in a markdown fence, adding a preamble
     * sentence, etc). Used by DefectAnalysisService, which parses the
     * reply as JSON and needs that to actually succeed.
     *
     * @throws AIServiceUnavailableException if Ollama is unreachable, or
     *                                       fails to respond successfully after retries.
     */
    public function generate(string $prompt, bool $jsonMode = false): string
    {
        $log = Log::channel((string) config('chatbot.log_channel'));
        $startedAt = microtime(true);

        $endpoint = $this->baseUrl.'/api/generate';

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->retry(self::RETRIES, self::RETRY_DELAY_MS)
                ->post('/api/generate', [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'stream' => false,
                    // qwen3.5 is a hybrid-reasoning model (see its "thinking"
                    // capability in /api/tags): without this, Ollama runs a
                    // full reasoning pass before emitting any output, which
                    // with stream=false is buffered server-side and can hang
                    // well past any sane request timeout for a chat UI.
                    'think' => false,
                    // Without an explicit num_ctx, Ollama uses the model's
                    // own default context window (often only 2048-4096
                    // tokens) - far smaller than this app's real prompts
                    // can get (a single PDF's full text alone can run to
                    // tens of thousands of tokens). Once a prompt exceeds
                    // num_ctx, Ollama silently drops the OLDEST tokens
                    // rather than erroring, which is a real, confirmed bug:
                    // a PDF Q&A request only ever "saw" the last ~25 lines
                    // of a 251-line document and reported real content near
                    // the START of the document as not found. See
                    // config/ollama.php's context_window docblock.
                    'options' => ['num_ctx' => $this->contextWindow],
                    ...($jsonMode ? ['format' => 'json'] : []),
                ]);
        } catch (ConnectionException|RequestException $e) {
            $log->error('Ollama request failed', [
                'url' => $endpoint,
                'model' => $this->model,
                'exception' => $e->getMessage(),
            ]);

            throw new AIServiceUnavailableException(
                "Could not reach Ollama at {$endpoint}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->failed()) {
            $log->error('Ollama responded with a failure status', [
                'url' => $endpoint,
                'model' => $this->model,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new AIServiceUnavailableException(
                "Ollama returned HTTP {$response->status()} from {$endpoint}: {$response->body()}",
            );
        }

        // Ollama's own field name is "response", not "answer" - the
        // /api/chat endpoint reads this and re-wraps it as "answer" in
        // its own JSON response to callers.
        $answer = $response->json('response');

        if (! is_string($answer)) {
            $log->error('Ollama returned an unexpected response shape', [
                'url' => $endpoint,
                'model' => $this->model,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new AIServiceUnavailableException(
                "Ollama returned HTTP {$response->status()} with no string \"response\" field. Body: {$response->body()}",
            );
        }

        $log->info('Ollama generate succeeded', [
            'model' => $this->model,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'answer_length' => strlen($answer),
        ]);

        return $answer;
    }
}
