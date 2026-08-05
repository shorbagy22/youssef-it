# Ollama API

How the chatbot talks to Ollama, and what each class in the chat pipeline
does. See [`architecture.md`](architecture.md) for the broader layering
rules this pipeline follows.

## The Ollama HTTP API

Ollama exposes a local HTTP server (default `http://localhost:11434`) with
no authentication - it's expected to run on the same machine or a trusted
local network. This application only uses two of its endpoints.

### `POST /api/generate`

The main completion endpoint. Request body:

```json
{
  "model": "llama3.1",
  "system": "You are the CompanyAIChatbot assistant...",
  "prompt": "User: What is our vacation policy?",
  "stream": false
}
```

- `model` - which locally-installed Ollama model to use.
- `system` - the system prompt (persona and behavior rules), kept separate
  from `prompt` so it doesn't get mixed into the model's turn-taking.
- `prompt` - the user-facing content: prior conversation turns flattened
  into text, ending with the current question.
- `stream: false` - request the full response in one JSON body instead of
  newline-delimited chunks. Ollama supports true token-by-token streaming
  (`stream: true`), but this application doesn't use it yet - see
  "Streaming" below.

Response body (non-streaming):

```json
{
  "model": "llama3.1",
  "response": "Full time employees accrue...",
  "done": true
}
```

Only the `response` field is read; everything else Ollama returns is
ignored.

### `GET /`

Ollama's root endpoint returns a plain `200 Ollama is running` with no
model load required. Used as a cheap health check - it confirms the
server process is up without the cost of running inference.

### Streaming

Requirement 7 asks for a "streaming placeholder" in the UI. Ollama's
`/api/generate` supports `stream: true`, which returns the response as a
sequence of newline-delimited JSON chunks instead of one body. This
application still uses `stream: false` - implementing true streaming
would mean reading the HTTP response incrementally and pushing partial
text to the browser (e.g. via Server-Sent Events), which is a larger
change than Phase 2's scope. The seams for it already exist:
`OllamaClient::generate()` is the only place that would need to change to
start reading a stream, and the Blade page's `chatPage.send()` JS
function has a comment marking exactly where incremental chunks would be
appended instead of the single finished answer.

## The chat pipeline

```
Blade UI (chat/index.blade.php)
    -> ChatController::send()
    -> ChatAction::handle()
    -> ChatService::handle()
    -> PromptBuilder (builds system + user prompt)
    -> LLMClient contract -> OllamaClient (calls Ollama)
```

### `App\Http\Controllers\ChatController`

Two methods: `index()` renders the chat page; `send()` validates the
incoming JSON (`message` required, optional `history` array of
`{role, content}` turns), builds a `ChatRequest` DTO, calls `ChatAction`,
and translates the result into JSON - `{"answer": "..."}` on success, or
a `503 {"error": "..."}` if `OllamaUnavailableException` was thrown. No
business logic lives here, per the Clean Architecture rule that
Controllers only receive, validate, delegate, and respond.

### `App\Actions\ChatAction`

A thin use-case wrapper around `ChatService::handle()` - the single thing
`ChatController` calls, matching the same "Controller calls one Action"
convention as `GetSystemStatusAction`. Kept separate from `ChatService` so
future entry points (a queued job, an artisan command) could reuse the
business logic without going through HTTP.

### `App\Services\ChatService`

The actual business logic: turns a `ChatRequest` into a `ChatResponse` by
asking `PromptBuilder` for the system and user prompts, then asking the
injected `LLMClient` to generate an answer. No HTTP, no controllers - it
depends on the `LLMClient` interface, not `OllamaClient` directly, so it
never needs to know an LLM call happens over HTTP at all.

### `App\Services\PromptBuilder`

Pure string composition, no I/O. `buildSystemPrompt(?string $context)`
returns the assistant's persona and behavior instructions; `$context` is
an unused parameter reserved for future retrieval-augmented generation -
always `null` today, since Phase 2 has no document retrieval.
`buildUserPrompt(string $message, array $history)` flattens prior turns
into `User: .../Assistant: ...` lines followed by the new message.

### `App\Contracts\LLMClient`

The interface `ChatService` depends on: `generate(string $systemPrompt,
string $userPrompt): string` and `isHealthy(): bool`. This is the
Dependency Inversion seam - swapping the underlying model server, or
mocking it in tests, never requires touching `ChatService`. Bound to
`OllamaClient` in `AppServiceProvider::register()`.

### `App\Services\OllamaClient`

The only class that knows Ollama's request/response format. Implements
`LLMClient` using Laravel's `Http` client exclusively (no shelling out to
the `ollama` CLI, no SDK). Configurable via `config/ollama.php`
(`base_url`, `model`, `timeout`, `retries`, `retry_delay_ms`, all
env-backed). Uses `->retry()` to cover transient connection failures and
bad status codes automatically; once retries are exhausted, or the
response is missing a string `response` field, it throws
`OllamaUnavailableException`. Every attempt and failure is logged to the
`chatbot` log channel (`storage/logs/chatbot.log`).

### `App\Exceptions\OllamaUnavailableException`

Thrown by `OllamaClient::generate()` when Ollama can't be reached or
fails to respond successfully. Caught specifically in `ChatController`
and mapped to HTTP 503, per the standing "return 503 if Ollama is
unavailable" architecture decision.

### `App\DTOs\ChatRequest` / `App\DTOs\ChatResponse`

Immutable data carried between layers. `ChatRequest` holds the validated
`message` and prior `history`. `ChatResponse` holds just `answer` for
now - no `sources` field, since there's no document retrieval to
attribute answers to yet. That field can be added additively once
SharePoint retrieval lands, without any other layer needing to change.

## Configuration

| Env var | Default | Purpose |
|---|---|---|
| `OLLAMA_BASE_URL` | `http://localhost:11434` | Ollama server address |
| `OLLAMA_MODEL` | `llama3.1` | Model to use for generation |
| `OLLAMA_TIMEOUT` | `120` | Per-request timeout, in seconds |
| `OLLAMA_RETRIES` | `2` | Retry attempts beyond the first try |
| `OLLAMA_RETRY_DELAY_MS` | `250` | Delay between retries, in milliseconds |

## Testing

All tests mock Ollama via Laravel's `Http::fake()` - no test makes a real
network call, and none require a running Ollama server. See
`tests/Unit/Services/OllamaClientTest.php` for the HTTP-level cases
(success, failure status, connection failure, malformed response, health
check) and `tests/Feature/ChatTest.php` for the full request/response
cycle through the controller, including the 503 path.
