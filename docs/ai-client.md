# AI Service Client

How the chatbot talks to the company's centralized AI HTTP endpoint, and
an explanation of every class in the chat pipeline. See
[`architecture.md`](architecture.md) for how this fits into the broader
Clean Architecture layering.

## Ownership

The company IT department owns SharePoint, Excel synchronization, data
ingestion, and the AI model (including Ollama) entirely, behind one HTTP
endpoint. Laravel has no integration with any of those systems directly -
it POSTs a question to the endpoint and returns the answer. There is no
Microsoft Graph integration, no Azure App Registration, no API key, and
no bearer token: the endpoint is called as configured, unauthenticated,
matching what was specified when this was built. If IT's endpoint later
requires authentication, that's a small, isolated change to
`AIClient::generate()`/`isHealthy()` only - nothing else in the app would
need to change, per the same Dependency Inversion seam that made this
whole refactor possible in the first place.

## Request/response contract (assumed, pending IT's published spec)

IT hasn't published a formal API contract as of this writing. The shapes
below are this app's own reasonable assumption, isolated to a single
class (`AIClient`) so they're a one-line change if IT's real contract
differs.

**Request** - `POST {AI_API_URL}`, JSON body:

```json
{
  "question": "User: What is our vacation policy?",
  "system": "You are the CompanyAIChatbot assistant..."
}
```

- `question` - the user-facing prompt: prior conversation turns flattened
  into text (built by `PromptBuilder::buildUserPrompt()`), ending with the
  current message. This is the literal field the "POST the user's
  question as JSON" requirement asked for.
- `system` - the assistant's persona and behavior instructions (built by
  `PromptBuilder::buildSystemPrompt()`). Included so IT's endpoint can use
  it if useful; dropping it would have silently regressed the assistant's
  persona/conciseness behavior with nothing asking for that.

**Response** - expected JSON body:

```json
{
  "answer": "Full time employees accrue..."
}
```

Only the `answer` field is read; anything else in the response is
ignored.

**Health check** - `GET {AI_API_URL}/health`. Requirement 9 said "if the
endpoint exists" - `AIClient::isHealthy()` treats any non-2xx response
(including a 404 if no such route exists) or connection failure as simply
unhealthy, never throwing. This is an assumed path, same caveat as above.

## Classes

### `App\Services\AIClient`

Implements the `LLMClient` contract - the only class that knows this
endpoint's request/response shape. Uses Laravel's `Http` client
exclusively. Configurable via `config/ai.php` (`base_url`, `timeout`,
`retries`, `retry_delay_ms`, all env-backed). Uses `->retry()` to cover
transient connection failures and bad status codes automatically; once
retries are exhausted, or the response is missing a string `answer`
field, it throws `AIServiceUnavailableException`. Every attempt and
failure is logged to the `chatbot` log channel
(`storage/logs/chatbot.log`) - duration and answer length, never the
question or answer content itself.

### `App\Contracts\LLMClient`

Unchanged from Phase 2: `generate(string $systemPrompt, string
$userPrompt): string` and `isHealthy(): bool`. `ChatService` depends on
this interface, never on `AIClient` directly - the Dependency Inversion
seam that let this entire refactor happen without touching `ChatService`,
`ChatAction`, `ChatController`, or the Blade UI. Bound to `AIClient` in
`AppServiceProvider::register()`.

### `App\Exceptions\AIServiceUnavailableException`

Thrown by `AIClient::generate()` when the AI service can't be reached or
fails to respond successfully. Caught specifically in `ChatController`
and mapped to HTTP 503.

### `App\Services\ChatService` / `App\Actions\ChatAction` / `App\Http\Controllers\ChatController`

Unchanged from Phase 2 - see the class-by-class breakdown in git history
if needed. None of these classes know or care that the concrete
`LLMClient` implementation changed from `OllamaClient` to `AIClient`.

### `App\Services\PromptBuilder`

Unchanged from Phase 2, still builds the system and user prompt text
consumed by `AIClient::generate()`. Its `?string $context` parameter on
`buildSystemPrompt()` remains an unused, reserved seam - Laravel has no
document store or retrieval logic of its own now, so nothing populates it
today.

## Configuration

| Env var | Default | Purpose |
|---|---|---|
| `AI_API_URL` | `http://localhost:8000` | Base URL of the company AI HTTP endpoint |
| `AI_TIMEOUT` | `120` | Per-request timeout, in seconds |
| `AI_RETRIES` | `2` | Retry attempts beyond the first try |
| `AI_RETRY_DELAY_MS` | `250` | Delay between retries, in milliseconds |

## Testing

All tests mock the AI service via Laravel's `Http::fake()` - no test
makes a real network call. `AI_API_URL` is pinned to
`http://ai-service.test` in `phpunit.xml` so no test can accidentally
reach the real internal endpoint if a fake is missed. See
`tests/Unit/Services/AIClientTest.php` for the HTTP-level cases (success,
failure status, connection failure, malformed response, health check) and
`tests/Feature/ChatTest.php` for the full request/response cycle through
the controller, including the 503 path.
