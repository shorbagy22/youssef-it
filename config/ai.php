<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI Service Configuration
|--------------------------------------------------------------------------
|
| Connection details for the company's centralized AI HTTP endpoint,
| owned and operated by IT. This is the *only* external service Laravel
| talks to for chat - SharePoint access, Excel sync, data ingestion, and
| the AI model itself (including Ollama) all live behind this one
| service now. Used by App\Services\AIClient. See docs/ai-client.md.
|
*/

return [

    // Base URL of the AI HTTP endpoint, e.g. "http://10.10.10.15:8000".
    'base_url' => env('AI_API_URL', 'http://localhost:8000'),

    // Request timeout, in seconds.
    'timeout' => env('AI_TIMEOUT', 120),

    // Number of retry attempts on connection failure, beyond the first try.
    'retries' => env('AI_RETRIES', 2),

    // Delay between retry attempts, in milliseconds.
    'retry_delay_ms' => env('AI_RETRY_DELAY_MS', 250),

];
