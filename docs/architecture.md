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
- **Contracts** (`app/Contracts`) are interfaces defining behavior (e.g. a
  future `SharePointConnectorContract`). Actions depend on these, never on
  concrete classes — this is the Dependency Inversion seam that keeps
  services swappable and testable.
- **Services** (`app/Services`) are concrete implementations of Contracts
  that talk to external systems (SharePoint via Microsoft Graph, Ollama's
  HTTP API).
- **Repositories** (`app/Repositories`) wrap Eloquent/persistence behind an
  interface, used only where a feature needs its own data access beyond
  what a Model already provides.
- **DTOs** (`app/DTOs`) are immutable, readonly data carriers passed between
  layers (e.g. `SystemStatusData`) so views and controllers depend on a
  stable shape instead of raw arrays.
- **ValueObjects** (`app/ValueObjects`) are small, self-validating types —
  currently the `ConnectionStatus` backed enum.
- **Exceptions** (`app/Exceptions`) are meaningfully-named custom exceptions
  so calling code can catch specific failure modes.
- **Support** (`app/Support`) holds small, framework-agnostic helpers that
  don't belong to any other layer (e.g. the future `PromptBuilder`).

Every service is bound and injected via Laravel's container — nothing is
manually instantiated with `new` outside of tests and factories.

## Phase 1 (current)

Phase 1 is the application skeleton only: Breeze authentication, a
dashboard with **fake** system status cards, the folder structure above
(mostly empty, documented via `.gitkeep` notes), and full tooling. There is
no SharePoint, Ollama, or chat functionality yet.

`GetSystemStatusAction` returns hardcoded `ConnectionStatus` values for
SharePoint, Ollama, the database, and authentication. Each fake value is
commented with what its real check will become. Because the dashboard view
and controller depend only on the `SystemStatusData` DTO shape, swapping a
fake status for a real check later requires no changes outside the Action.

## Planned: SharePoint → Ollama chat pipeline

The chat feature (not yet built) will follow this pipeline, approved
explicitly with **no RAG, embeddings, vector databases, Azure AI Search, or
local document indexing**:

```
User
  → PHP Web Interface (Controller)
  → ChatAction orchestrates, in order:
      SharePointConnector   — downloads latest SharePoint documents
      DocumentExtractor     — extracts text from PDF/DOCX/XLSX/TXT
      PromptBuilder         — builds one prompt from the extracted text
      OllamaClient          — POSTs to http://localhost:11434/api/generate
  → Response: {"answer": "...", "sources": [...]}
```

Key constraints for this pipeline, carried forward into every future
milestone that touches it:

- **No Microsoft Graph SDK** — SharePoint access goes through Laravel's
  `Http` client only.
- **`SharePointConnector` and `OllamaClient` are completely independent** —
  neither knows the other exists. Only the orchestrating Action sequences
  them.
- **No caching, queues, background jobs, vector databases, embeddings, or
  RAG.** Every request downloads fresh documents and calls Ollama directly.
  The architecture is kept modular enough that these *could* be added later
  without changing the Action's public shape, but none are implemented now.
- If SharePoint or Ollama is unavailable, the endpoint returns HTTP 503.
- Configuration lives in `config/sharepoint.php` and `config/ollama.php`,
  populated entirely from `.env` (`SHAREPOINT_TENANT_ID`,
  `SHAREPOINT_CLIENT_ID`, `SHAREPOINT_CLIENT_SECRET`, `SHAREPOINT_SITE_ID`,
  `SHAREPOINT_DRIVE_ID`, `OLLAMA_BASE_URL`, `OLLAMA_MODEL`).

This is documented here for continuity but is out of scope for Phase 1.
