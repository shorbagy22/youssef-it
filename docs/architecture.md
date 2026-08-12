# Architecture

## Current shape

**Two independent chat pipelines coexist.** Neither was asked to replace
the other, so both remain functional side by side:

```
Web /chat pipeline (Blade UI):
  ChatController → ChatAction → ChatService
    → PromptBuilder (builds system + user prompt)
    → LLMClient contract → AIClient → company AI HTTP endpoint (AI_API_URL)

/api/chat data pipeline (JSON API):
  sources:sync (scheduled every 10 min) → SyncSourcesAction
    → reads a local/downloaded Excel file → extracts NRFT/PPM/defects
    → data_records (MySQL)

  Api\ChatController → ChatDataService (builds prompt from recent
    data_records) → OllamaClient → Ollama directly (OLLAMA_BASE_URL)
```

The first pipeline treats the AI service as a black box that owns
SharePoint, Excel sync, and data ingestion itself - Laravel only forwards
a question and department. The second pipeline does the opposite:
Laravel owns reading and parsing Excel data, storing it structurally, and
building the prompt itself, calling Ollama's raw API with no wrapper in
between. See [`ai-client.md`](ai-client.md) for the first and
[`data-pipeline-api.md`](data-pipeline-api.md) for the second.

## Layering

The application follows a strict Clean Architecture layering. Requests
flow in one direction only:

```
Controller → Action → Contracts → Services → External Systems
```

- **Controllers** (`app/Http/Controllers`) contain no business logic. They
  inject an Action, call it, and pass the result to a view or JSON response.
- **Actions** (`app/Actions`) hold the business logic for one use case
  (e.g. `GetSystemStatusAction`, `ChatAction`). They orchestrate calls to
  Contracts and are the only layer allowed to coordinate multiple services.
- **Contracts** (`app/Contracts`) are interfaces defining behavior (e.g.
  `LLMClient`). Actions and Services depend on these, never on concrete
  classes — this is the Dependency Inversion seam that keeps services
  swappable and testable. It's what let the entire SharePoint/Ollama
  architecture be replaced by a single HTTP client without touching
  `ChatService`, `ChatAction`, `ChatController`, or the Blade UI at all -
  only the concrete class bound to `LLMClient` changed.
- **Services** (`app/Services`) are concrete implementations of Contracts
  that talk to external systems (`AIClient` talking to the company's AI
  endpoint, `OllamaClient` talking to Ollama directly), plus other
  business-logic classes that sit below an Action (`ChatService`,
  `PromptBuilder`, `ChatDataService`).
- **Repositories** (`app/Repositories`) wrap Eloquent queries, isolating
  persistence details from business logic. Currently empty - `Source`/
  `DataRecord` queries are simple enough to live directly in their
  Action/Controller for now; populate this once a feature needs more.
- **DTOs** (`app/DTOs`) are immutable, readonly data carriers passed
  between layers (e.g. `SystemStatusData`, `ChatRequest`, `ChatResponse`)
  so callers depend on a stable shape instead of raw arrays.
- **ValueObjects** (`app/ValueObjects`) are small, self-validating types —
  `ConnectionStatus` and the legacy `Department` enum used to seed the four
  initial database departments.
- **Exceptions** (`app/Exceptions`) are meaningfully-named custom
  exceptions so calling code can catch specific failure modes (e.g.
  `AIServiceUnavailableException`, reused by both `AIClient` and
  `OllamaClient` since both represent the same category of failure - an
  unreachable AI backend).
- **Imports** (`app/Imports`) holds `maatwebsite/excel` import classes -
  currently just `RawRowsImport`, positional row access with no heading
  row assumed.
- **Support** (`app/Support`) holds small, framework-agnostic helpers that
  don't belong to any other layer. Still empty - nothing built so far has
  needed it.

Every service is bound and injected via Laravel's container — nothing is
manually instantiated with `new` outside of tests and factories.

## History

This project went through several rounds of real architecture change,
each one landed as its own commit. Full detail lives in git log and
`project-roadmap.md`'s history; summarized here for context:

1. **Phase 1** - application skeleton, Breeze auth, dashboard with fake
   status cards, tooling.
2. **Phase 2** - chat feature against a locally-run Ollama server.
3. **Phase 3** - SharePoint reframed as a structured Excel data source,
   synced into MySQL on a schedule via Microsoft Graph (client-credentials
   auth, dynamic Site/Drive ID resolution). Hardened twice more: made
   fully config-driven with no hardcoded IDs, then given richer
   diagnostics (`sharepoint:test`) while investigating a real tenant's
   admin-consent requirement.
4. **"Laravel is only an AI client" refactor** - the company IT department
   took ownership of SharePoint, Excel sync, data ingestion, and the AI
   model entirely, exposing one HTTP endpoint. Every class from Phases 2-3
   that talked to Ollama or Microsoft Graph directly (`OllamaClient`,
   `MicrosoftGraphClient`, `SharePointExcelService`, `ExcelFileProvider`,
   `SyncSharePointExcelFilesAction`, `SyncedDocumentRepository`,
   `SyncedDocument`, both `sharepoint:*` console commands, and their
   configs/migrations) was removed. `AIClient` replaces `OllamaClient` as
   the `LLMClient` contract's bound implementation.
5. **Factory data pipeline** - a second, independent architecture request
   reversed course on "AI backend does everything": Laravel now reads
   locally-synced Excel files itself, extracts structured NRFT/PPM/defects
   data into MySQL every 10 minutes, and calls Ollama's raw API directly
   for a new `/api/chat` JSON endpoint - a genuinely different integration
   from the web `/chat` pipeline (4), not a replacement of it. New
   `OllamaClient` class (same name as the one removed in step 4, but a
   distinct implementation with no shared history - it's not bound to
   `LLMClient` and isn't used by the web pipeline).

## What's not built

No RAG, embeddings, or vector database in either pipeline. The web
`/chat` pipeline still delegates all data ingestion to the external AI
service; the `/api/chat` pipeline does its own structured extraction but
never sends raw file content to the AI, and has no admin UI, no auth, and
no document-browsing feature - `sources`/`data_records` are managed
purely via `POST /api/sources` and `sources:sync`. `documents.index` and
`settings.index` remain unrelated placeholder pages from an earlier,
unbuilt admin-panel proposal.
