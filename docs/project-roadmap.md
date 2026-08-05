# Project Roadmap

## Phase 1 — Foundation (current)

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

## Beyond Phase 1

The SharePoint → Ollama chat pipeline is fully designed (see
[`architecture.md`](architecture.md)) and approved, but not yet built.
Expected milestones, each landing as its own commit:

1. **`OllamaClient`** — thin HTTP client for `POST /api/generate` against a
   local Ollama instance, independent of everything else.
2. **`SharePointConnector`** — downloads documents from SharePoint via
   Laravel's `Http` client (no Microsoft Graph SDK), independent of
   `OllamaClient`.
3. **`DocumentExtractor`** — extracts text from PDF/DOCX/XLSX/TXT.
4. **`PromptBuilder`** — composes one prompt from extracted document text
   and the user's question.
5. **Orchestrating Action + Controller/route** — wires the four pieces
   above into a single request/response cycle returning
   `{"answer": "...", "sources": [...]}`, with HTTP 503 if SharePoint or
   Ollama is unavailable.
6. **Chat UI** — replaces the Phase 1 placeholder `chat/index.blade.php`
   with a real interface calling the endpoint above.
7. **Dashboard status cards go live** — `GetSystemStatusAction` swaps its
   hardcoded SharePoint/Ollama values for real connectivity checks.

Explicitly out of scope, per standing architectural decision: RAG,
embeddings, vector databases, Azure AI Search, local document indexing,
document caching, queues, and background jobs. The pipeline is kept
modular enough that these *could* be added later without changing the
orchestrating Action's public shape, but none are planned.
