<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ollama Configuration
|--------------------------------------------------------------------------
|
| Connection details for the local Ollama server the chatbot sends prompts
| to, used by App\Services\OllamaClient.
|
*/

return [

    // Base URL of the local Ollama server's HTTP API.
    'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),

    // Default model Ollama should use for generation requests.
    'model' => env('OLLAMA_MODEL', 'llama3.1'),

    // Request timeout, in seconds, for calls to Ollama.
    'timeout' => env('OLLAMA_TIMEOUT', 120),

    // Number of retry attempts on connection failure, beyond the first try.
    'retries' => env('OLLAMA_RETRIES', 2),

    // Delay between retry attempts, in milliseconds.
    'retry_delay_ms' => env('OLLAMA_RETRY_DELAY_MS', 250),

];
