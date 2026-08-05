# Project Roadmap

## Phase 1 — Foundation (complete)

Application skeleton only. No SharePoint, Ollama, RAG, or chat
functionality.

| Task | Description |
|---|---|
| P1.1 | Scaffold Laravel 12 project, MySQL configured |
| P1.2 | Install Breeze auth, restyle from Tailwind to Bootstrap 5 |
| P1.3 | Clean Architecture folder skeleton (`Actions/Contracts/DTOs/Exceptions/Http/Models/Repositories/Services/Support/ValueObjects`) |
| P1.4 | Config files (`chatbot.php`, `ollama.php`, `sharepoint.php`) and env vars, dedicated `chatbot` log channel |
| P1.5 | Dashboard with `ConnectionStatus` enum and fake status cards (SharePoint, Ollama, Database, Authentication) |
| P1.6 | Tooling: PHPStan/Larastan (level 8), Pint, GitHub Actions CI |
| P1.7 | Unit and feature tests for everything built in Phase 1 |
| P1.8 | Docs: README, architecture.md, development.md, project-roadmap.md (this file) |
| P1.9 | Final validation (Pint, PHPStan, Pest) and a single Phase 1 commit |

## Phase 2 — Local Ollama chatbot (complete)

A complete, working chat feature against a local Ollama server - no
SharePoint yet, no document retrieval. See
[`ollama-api.md`](ollama-api.md) for the full pipeline and class-by-class
explanation.

| Task | Description |
|---|---|
| P2.1 | `config/ollama.php` retries/delay, `OllamaUnavailableException`, `LLMClient` contract |
| P2.2 | `ChatRequest`/`ChatResponse` DTOs |
| P2.3 | `OllamaClient` (Http client, retries, timeout, structured logging, health check) and `PromptBuilder` |
| P2.4 | `ChatService`, `ChatAction`, `LLMClient` → `OllamaClient` container binding |
| P2.5 | `ChatController` (`index`/`send`), routes |
| P2.6 | Chat Blade UI: message history, typing indicator, streaming placeholder |
| P2.7 | Unit and feature tests, Ollama fully mocked via `Http::fake()` |
| P2.8 | Docs (`ollama-api.md`, this file), final validation, a single Phase 2 commit |

## Beyond Phase 2

The SharePoint side of the architecture (see
[`architecture.md`](architecture.md)) is designed and approved, but not
yet built:

1. **`SharePointConnector`** — downloads documents from SharePoint via
   Laravel's `Http` client (no Microsoft Graph SDK), independent of
   `OllamaClient`.
2. **`DocumentExtractor`** — extracts text from PDF/DOCX/XLSX/TXT.
3. **`PromptBuilder` gains retrieval context** — the existing `?string
   $context` parameter on `buildSystemPrompt()` starts being passed
   extracted document text instead of always `null`.
4. **`ChatService`/`ChatAction` gain the retrieval step** — SharePoint
   download and extraction happen before prompt building; the response
   grows a `sources` field alongside `answer`.
5. **Dashboard status cards go live** — `GetSystemStatusAction` swaps its
   hardcoded SharePoint/Ollama values for real connectivity checks (Ollama
   can now use `OllamaClient::isHealthy()`).

Explicitly out of scope, per standing architectural decision: RAG,
embeddings, vector databases, Azure AI Search, local document indexing,
document caching, queues, and background jobs. The pipeline is kept
modular enough that these *could* be added later without changing the
orchestrating Action's public shape, but none are planned.
