# Architecture

## Current shape

**Laravel is only an AI client.** The company IT department owns
SharePoint, Excel synchronization, data ingestion, and the AI model
(including Ollama) behind a single HTTP endpoint. Laravel's entire
external dependency surface for the chat feature is that one endpoint -
no Microsoft Graph integration, no Azure App Registration, no SharePoint
code, no local Ollama process.

```
Blade UI → ChatController → ChatAction → ChatService
    → PromptBuilder (builds system + user prompt)
    → LLMClient contract → AIClient → company AI HTTP endpoint
```

See [`ai-client.md`](ai-client.md) for the full request/response shape,
retry/timeout/logging behavior, and an explanation of every class in this
pipeline.

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
  endpoint), plus other business-logic classes that sit below an Action
  (`ChatService`, `PromptBuilder`).
- **Repositories** (`app/Repositories`) wrap Eloquent queries, isolating
  persistence details from business logic. Currently empty - populated
  once a feature needs its own persistence beyond what Models/migrations
  already cover.
- **DTOs** (`app/DTOs`) are immutable, readonly data carriers passed
  between layers (e.g. `SystemStatusData`, `ChatRequest`, `ChatResponse`)
  so callers depend on a stable shape instead of raw arrays.
- **ValueObjects** (`app/ValueObjects`) are small, self-validating types —
  currently the `ConnectionStatus` backed enum.
- **Exceptions** (`app/Exceptions`) are meaningfully-named custom
  exceptions so calling code can catch specific failure modes (e.g.
  `AIServiceUnavailableException`).
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
4. **This refactor** - the company IT department took ownership of
   SharePoint, Excel sync, data ingestion, and the AI model entirely,
   exposing one HTTP endpoint. Every class from Phases 2-3 that talked to
   Ollama or Microsoft Graph directly (`OllamaClient`,
   `MicrosoftGraphClient`, `SharePointExcelService`, `ExcelFileProvider`,
   `SyncSharePointExcelFilesAction`, `SyncedDocumentRepository`,
   `SyncedDocument`, both `sharepoint:*` console commands, and their
   configs/migrations) was removed. `AIClient` replaces `OllamaClient` as
   the `LLMClient` contract's bound implementation - the only change the
   chat pipeline needed.

## What's not built

No AI, RAG, embeddings, or data ingestion logic lives in Laravel - that's
entirely the AI service's responsibility now. There is no SharePoint or
document-browsing feature; `documents.index` and `settings.index` remain
placeholder pages.
