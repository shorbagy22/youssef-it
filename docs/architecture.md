# Architecture

## Layering

The application follows a strict Clean Architecture layering. Requests flow
in one direction only:

```
Controller → Action → Contracts → Services → External Systems
```

- **Controllers** (`app/Http/Controllers`) are invokable, single-purpose, and
  contain no business logic. They inject an Action, call it, and pass the
  result to a view or JSON response.
- **Actions** (`app/Actions`) hold the business logic for one use case
  (e.g. `GetSystemStatusAction`). They orchestrate calls to Contracts and
  are the only layer allowed to coordinate multiple services.
- **Contracts** (`app/Contracts`) are interfaces defining behavior (e.g.
  `LLMClient`, a future `SharePointConnectorContract`). Actions and Services
  depend on these, never on concrete classes — this is the Dependency
  Inversion seam that keeps services swappable and testable.
- **Services** (`app/Services`) are concrete implementations of Contracts
  that talk to external systems (`OllamaClient` talking to Ollama's HTTP
  API; a future `SharePointConnector` talking to SharePoint), plus other
  business-logic classes that sit below an Action (`ChatService`,
  `PromptBuilder`).
- **Repositories** (`app/Repositories`) wrap Eloquent/persistence behind an
  interface, used only where a feature needs its own data access beyond
  what a Model already provides.
- **DTOs** (`app/DTOs`) are immutable, readonly data carriers passed between
  layers (e.g. `SystemStatusData`, `ChatRequest`, `ChatResponse`) so views
  and controllers depend on a stable shape instead of raw arrays.
- **ValueObjects** (`app/ValueObjects`) are small, self-validating types —
  currently the `ConnectionStatus` backed enum.
- **Exceptions** (`app/Exceptions`) are meaningfully-named custom exceptions
  so calling code can catch specific failure modes (e.g.
  `OllamaUnavailableException`).
- **Support** (`app/Support`) holds small, framework-agnostic helpers that
  don't belong to any other layer. Still empty - nothing built so far has
  needed it.

Every service is bound and injected via Laravel's container — nothing is
manually instantiated with `new` outside of tests and factories.

## Phase 1 (complete)

Phase 1 shipped the application skeleton: Breeze authentication, a
dashboard with **fake** system status cards, the folder structure above
(mostly empty, documented via `.gitkeep` notes), and full tooling. It had
no SharePoint, Ollama, or chat functionality.

`GetSystemStatusAction` still returns hardcoded `ConnectionStatus` values
for SharePoint, the database, and authentication (Ollama's card is the
next one that could go live, now that `OllamaClient::isHealthy()` exists -
see the roadmap). Because the dashboard view and controller depend only on
the `SystemStatusData` DTO shape, swapping a fake status for a real check
requires no changes outside the Action.

## Phase 2 (current): local Ollama chatbot

Phase 2 built a complete chat feature against a local Ollama server - see
[`ollama-api.md`](ollama-api.md) for the full pipeline, the Ollama HTTP API
it relies on, and an explanation of every class. In short:

```
Blade UI → ChatController → ChatAction → ChatService
    → PromptBuilder (builds system + user prompt)
    → LLMClient contract → OllamaClient → Ollama
```

No SharePoint yet - every answer comes from the model alone, with no
company documents grounding it.

## Planned: SharePoint retrieval

SharePoint document retrieval (not yet built) will slot into the existing
pipeline above rather than replacing it, approved explicitly with **no
RAG, embeddings, vector databases, Azure AI Search, or local document
indexing**:

```
User
  → ChatController → ChatAction → ChatService orchestrates, in order:
      SharePointConnector   — downloads latest SharePoint documents
      DocumentExtractor     — extracts text from PDF/DOCX/XLSX/TXT
      PromptBuilder         — passes the extracted text as buildSystemPrompt()'s
                               $context parameter (already reserved for this)
      LLMClient (OllamaClient) — unchanged
  → Response: {"answer": "...", "sources": [...]}
```

Key constraints for this pipeline, carried forward into every future
milestone that touches it:

- **No Microsoft Graph SDK** — SharePoint access goes through Laravel's
  `Http` client only.
- **`SharePointConnector` and `OllamaClient` are completely independent** —
  neither knows the other exists. Only `ChatService` sequences them.
- **No caching, queues, background jobs, vector databases, embeddings, or
  RAG.** Every request downloads fresh documents and calls Ollama directly.
  The architecture is kept modular enough that these *could* be added later
  without changing `ChatService`'s public shape, but none are implemented
  now.
- If SharePoint is unavailable, the endpoint returns HTTP 503 (matching how
  `OllamaUnavailableException` already maps to 503 today).
- Configuration lives in `config/sharepoint.php`, populated entirely from
  `.env` (`SHAREPOINT_TENANT_ID`, `SHAREPOINT_CLIENT_ID`,
  `SHAREPOINT_CLIENT_SECRET`, `SHAREPOINT_SITE_ID`, `SHAREPOINT_DRIVE_ID`).

This is documented here for continuity but is out of scope for Phase 2.
