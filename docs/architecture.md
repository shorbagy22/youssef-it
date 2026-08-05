# Architecture

## Layering

The application follows a strict Clean Architecture layering. Requests flow
in one direction only:

```
Controller → Action → Contracts → Services → External Systems
```

`app/Console/Commands` is a second, non-HTTP entry point that follows the
same shape as Controllers: thin, no business logic, delegates entirely to
an Action (`SyncSharePointExcelFiles` → `SyncSharePointExcelFilesAction`).
Used for scheduled/background work instead of a web request.

- **Controllers** (`app/Http/Controllers`) contain no business logic. They
  inject an Action, call it, and pass the result to a view or JSON response.
- **Actions** (`app/Actions`) hold the business logic for one use case
  (e.g. `GetSystemStatusAction`, `SyncSharePointExcelFilesAction`). They
  orchestrate calls to Contracts and Repositories and are the only layer
  allowed to coordinate multiple services.
- **Contracts** (`app/Contracts`) are interfaces defining behavior (e.g.
  `LLMClient`, `ExcelFileProvider`). Actions and Services depend on these,
  never on concrete classes — this is the Dependency Inversion seam that
  keeps services swappable and testable.
- **Services** (`app/Services`) are concrete implementations of Contracts
  that talk to external systems (`OllamaClient` talking to Ollama;
  `MicrosoftGraphClient`/`SharePointExcelService` talking to SharePoint),
  plus other business-logic classes that sit below an Action (`ChatService`,
  `PromptBuilder`).
- **Repositories** (`app/Repositories`) wrap Eloquent queries, isolating
  persistence details from business logic. First real example:
  `SyncedDocumentRepository`, which is the only class that queries the
  `SyncedDocument` model directly.
- **DTOs** (`app/DTOs`) are immutable, readonly data carriers passed between
  layers (e.g. `SystemStatusData`, `ChatRequest`, `SharePointExcelFile`,
  `SyncResult`) so callers depend on a stable shape instead of raw arrays
  or raw external-API JSON.
- **ValueObjects** (`app/ValueObjects`) are small, self-validating types —
  `ConnectionStatus` and `SyncStatus`, both backed enums.
- **Exceptions** (`app/Exceptions`) are meaningfully-named custom exceptions
  so calling code can catch specific failure modes (e.g.
  `OllamaUnavailableException`, `SharePointException`).
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

## Phase 2 (complete): local Ollama chatbot

Phase 2 built a complete chat feature against a local Ollama server - see
[`ollama-api.md`](ollama-api.md) for the full pipeline, the Ollama HTTP API
it relies on, and an explanation of every class. In short:

```
Blade UI → ChatController → ChatAction → ChatService
    → PromptBuilder (builds system + user prompt)
    → LLMClient contract → OllamaClient → Ollama
```

No SharePoint grounding yet - every answer still comes from the model
alone (Phase 3 syncs SharePoint data, but the chat pipeline doesn't read
it yet - that's Phase 5).

## Phase 3 (current): SharePoint Excel sync

**This supersedes the "SharePoint retrieval feeds directly into the chat
prompt" plan from earlier phases.** SharePoint is now treated as a
structured Excel data source that syncs into MySQL on a configurable
schedule, independent of the chat pipeline above - see
[`sharepoint.md`](sharepoint.md) for the full pipeline, the Graph API it
relies on, and an explanation of every class, and
[`sharepoint-setup.md`](sharepoint-setup.md) for the runbook to configure
a real SharePoint site. In short:

```
SharePoint (Excel files)
  → Microsoft Graph API
  → MicrosoftGraphClient (auth + dynamic site/drive resolution + generic Graph HTTP calls)
  → SharePointExcelService (filters to Excel, returns DTOs)
  → SyncSharePointExcelFilesAction (list, compare, download, store)
  → SyncedDocumentRepository → MySQL (synced_documents) + local disk (raw bytes)
```

Triggered by the `sharepoint:sync-excel` console command on a daily
schedule, not by an HTTP request or a chat message - there's no
per-request document download anymore, which is a deliberate departure
from the original Phase 1/2 "always download fresh, never cache" plan.
That constraint applied to a design where SharePoint was queried live
per chat request; now that SharePoint is a synced data source instead,
the relevant guarantee is "the sync always re-checks the source," not
"every chat request re-downloads everything."

Excel file *content* isn't read yet - only raw file bytes and metadata
(name, SharePoint ID, modified date, sync status, checksum) are synced.
No AI, no RAG, no PDF/Word support - explicit, standing constraints.

Key constraints for this pipeline, carried forward into every future
milestone that touches it:

- **No Microsoft Graph SDK** — SharePoint access goes through Laravel's
  `Http` client only.
- **No RAG, embeddings, vector databases, or Azure AI Search.**
- **No queues or background jobs.** The sync runs synchronously within a
  single console command invocation.
- If SharePoint is unreachable, the sync command exits non-zero and logs
  the failure; an individual file's download failure marks just that file
  `Failed` and the run continues with the rest. If it's simply
  unconfigured (`SHAREPOINT_SITE_URL` empty), everything exits cleanly
  with no exceptions - see `sharepoint.md`.
- **The Graph Site ID and Drive ID are never configured directly** - both
  are resolved dynamically at runtime from `SHAREPOINT_SITE_URL` and
  `SHAREPOINT_DOCUMENT_LIBRARY`, so setting this up for a real environment
  is purely a `.env` change (see `sharepoint-setup.md`), never a code
  change.
- Configuration lives in `config/sharepoint.php`, populated entirely from
  `.env` (`SHAREPOINT_TENANT_ID`, `SHAREPOINT_CLIENT_ID`,
  `SHAREPOINT_CLIENT_SECRET`, `SHAREPOINT_SITE_URL`,
  `SHAREPOINT_DOCUMENT_LIBRARY`, `SHAREPOINT_EXCEL_FOLDER`,
  `SHAREPOINT_SYNC_SCHEDULE`).

## Planned: Excel parsing and chatbot data lookup

Not yet built - see [`project-roadmap.md`](project-roadmap.md)'s "Beyond
Phase 3" section for the Phase 4 (Excel Parser, Database Import, Data
Normalization) and Phase 5 (chatbot queries MySQL instead of calling
SharePoint) plan.
