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
    'base_url' => env('OLLAMA_BASE_URL', 'http://10.10.10.15:11434'),

    // Model to use for generation requests.
    'model' => env('OLLAMA_MODEL', 'qwen2.5:9b'),

    // Request timeout, in seconds.
    'timeout' => env('OLLAMA_TIMEOUT', 60),

];
