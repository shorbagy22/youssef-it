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

## Phase 3 — SharePoint Excel sync (complete)

**Architecture change from the original Phase 1/2 plan**: SharePoint is
now treated as a structured Excel data source that syncs into MySQL on a
daily schedule, not a generic document store queried per chat request.
See [`sharepoint.md`](sharepoint.md) for the full pipeline and
class-by-class explanation.

| Task | Description |
|---|---|
| P3.1 | `config/sharepoint.php` folder path, `synced_documents` migration, `SyncedDocument` model, `SyncStatus` enum |
| P3.2 | `SharePointExcelFile`/`SyncResult` DTOs, `ExcelFileProvider` contract, `SharePointException` |
| P3.3 | `MicrosoftGraphClient` (client-credentials auth, retries, healthCheck/listChildren/getItemMetadata/downloadContent) and `SharePointExcelService` (Excel filtering, DTOs, logging) |
| P3.4 | `SyncedDocumentRepository`, `SyncSharePointExcelFilesAction` (change detection, download, store), `sharepoint:sync-excel` console command + daily schedule, container binding |
| P3.5 | Unit and feature tests, Graph fully mocked via `Http::fake()` |
| P3.6 | Docs (`sharepoint.md`, this file), final validation, a single Phase 3 commit |

Explicitly out of scope for Phase 3, per standing instruction: AI, RAG,
embeddings, vector databases, PDF/Word parsing. Excel is the only
supported file type, and its *contents* aren't read yet - only raw file
bytes and metadata (name, SharePoint ID, modified date, sync status,
checksum) are synced.

## Beyond Phase 3

1. **Excel Parser** — reads the raw `.xlsx` files already synced to
   `storage/app/private/sharepoint-excel/`, using each `SyncedDocument`
   row to know which files are current.
2. **Database Import** — writes parsed spreadsheet rows into their own
   MySQL tables, separate from `synced_documents` (which only tracks
   file-level sync state, not row-level content).
3. **Data Normalization** — shapes the imported data into a consistent,
   queryable structure across whatever Excel formats/columns the source
   files use.
4. **Phase 5: chatbot connects to MySQL** — `ChatService` gains a
   data-lookup step ahead of prompt building, querying the normalized
   tables from Phase 4 instead of calling SharePoint directly per chat
   request. This is a departure from the original Phase 1/2 "SharePoint →
   Ollama per request, no caching" plan, superseded by the sync-based
   architecture adopted in Phase 3.
5. **Dashboard status cards go live** — `GetSystemStatusAction` swaps its
   hardcoded SharePoint/Ollama values for real connectivity checks
   (`SharePointExcelService::healthCheck()` and
   `OllamaClient::isHealthy()` already exist for this).

Explicitly out of scope, per standing architectural decision: RAG,
embeddings, vector databases, Azure AI Search, local document indexing,
queues, and background jobs. The pipeline is kept modular enough that
these *could* be added later without changing established public shapes,
but none are planned.
