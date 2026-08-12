<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ollama Configuration
|--------------------------------------------------------------------------
|
| Connection details for the Ollama server this application talks to
| directly for the /api/chat endpoint. Used by App\Services\OllamaClient.
| See docs/data-pipeline-api.md.
|
*/

return [

    // Base URL of the Ollama server's HTTP API.
    'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),

    // Model to use for generation requests.
    'model' => env('OLLAMA_MODEL', 'qwen3.5:9b'),

    // Request timeout, in seconds.
    'timeout' => env('OLLAMA_TIMEOUT', 300),

    // Fail quickly when the host cannot be reached while still allowing
    // enough time above for a cold model load and generation.
    'connect_timeout' => env('OLLAMA_CONNECT_TIMEOUT', 10),

    // Total attempts, including the first request. Only connection errors,
    // HTTP 429 responses, and server errors are retried.
    'attempts' => env('OLLAMA_ATTEMPTS', 2),
    'retry_delay_ms' => env('OLLAMA_RETRY_DELAY_MS', 500),

    // Qwen 3.5 supports hidden reasoning, but this data-grounded chatbot
    // benefits from lower latency and deterministic final-answer output.
    'think' => env('OLLAMA_THINK', false),

    // Private Ollama hosts are contacted directly by default instead of
    // inheriting HTTP(S)_PROXY from the machine. Set this only when the
    // Ollama connection genuinely requires a proxy.
    'proxy' => env('OLLAMA_PROXY'),

];
