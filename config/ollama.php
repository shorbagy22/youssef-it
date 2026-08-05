<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ollama Configuration
|--------------------------------------------------------------------------
|
| Connection details for the local Ollama server the chatbot sends prompts
| to. Nothing in the application calls Ollama yet - this is a later
| milestone. Defined now so the configuration surface is settled before
| that work begins.
|
*/

return [

    // Base URL of the local Ollama server's HTTP API.
    'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),

    // Default model Ollama should use for generation requests.
    'model' => env('OLLAMA_MODEL', 'llama3.1'),

    // Request timeout, in seconds, for calls to Ollama.
    'timeout' => env('OLLAMA_TIMEOUT', 120),

];
