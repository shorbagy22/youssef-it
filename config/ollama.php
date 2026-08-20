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
    'timeout' => env('OLLAMA_TIMEOUT', 120),

    // Context window (in tokens) requested via Ollama's own
    // "options.num_ctx" generate parameter - see OllamaClient::generate().
    // Without this, Ollama falls back to whatever default num_ctx the
    // model/Modelfile itself declares, which is commonly as small as
    // 2048-4096 tokens - far smaller than this app's real documents. A
    // single synced PDF source's full text alone (see PdfQaService) can
    // run to tens of thousands of tokens, and once a request's total
    // prompt exceeds num_ctx, Ollama/llama.cpp silently drops the
    // OLDEST tokens to make room for the newest ones rather than erroring
    // - confirmed as a real, live bug: a PDF Q&A request only "saw" the
    // last ~25 of a 251-line document and reported a real definition
    // near the START of the document as not found.
    //
    // 8192, NOT something larger: live-tested directly against this
    // app's real configured Ollama server (see OLLAMA_BASE_URL) with a
    // real ~17,000-token PDF document prompt - 8192 completed in ~38s,
    // but 24576 exceeded a 5-MINUTE wait with no response at all, and
    // 32768 exceeded 8 minutes. This server hits a real, severe
    // performance cliff somewhere between 8192 and 24576 tokens (almost
    // certainly a hardware ceiling - limited VRAM forcing CPU fallback,
    // or similar - not something fixable from this app's side). 8192 is
    // the largest value confirmed FAST on this actual server, still well
    // short of covering this app's largest real PDF documents in full -
    // see PdfQaService's docblock for the resulting, still-open
    // limitation this leaves.
    'context_window' => env('OLLAMA_CONTEXT_WINDOW', 8192),

];
