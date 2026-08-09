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

## Phase 2 — Local Ollama chatbot (complete, later superseded)

A complete, working chat feature against a locally-run Ollama server - no
SharePoint yet, no document retrieval.

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

`OllamaClient` and its direct-to-Ollama design were removed in the
"Company AI endpoint" refactor below. Everything else from this
phase - `LLMClient`, `ChatService`, `ChatAction`, `ChatController`, the
Blade UI - is unchanged and still in production use today.

## Phase 3 — SharePoint Excel sync (complete, later removed)

**Architecture change from the original Phase 1/2 plan**: SharePoint was
reframed as a structured Excel data source synced into MySQL on a
schedule, not a generic document store queried per chat request. Went
through three rounds: the initial sync pipeline, a config-only rework
(dynamic Site ID/Drive ID resolution, no hardcoded IDs), and a diagnostics
pass (`sharepoint:test`) while investigating a real tenant's admin-consent
requirement.

| Task | Description |
|---|---|
| P3.1 | `config/sharepoint.php`, `synced_documents` migration, `SyncedDocument` model, `SyncStatus` enum |
| P3.2 | `SharePointExcelFile`/`SyncResult` DTOs, `ExcelFileProvider` contract, `SharePointException` |
| P3.3 | `MicrosoftGraphClient` (client-credentials auth, retries, healthCheck/listChildren/getItemMetadata/downloadContent) and `SharePointExcelService` (Excel filtering, DTOs, logging) |
| P3.4 | `SyncedDocumentRepository`, `SyncSharePointExcelFilesAction` (change detection, download, store), `sharepoint:sync-excel` console command + schedule, container binding |
| P3.5 | Unit and feature tests, Graph fully mocked via `Http::fake()` |
| P3.6 | Docs, final validation, a single Phase 3 commit |
| P3.7 | Config-only placeholder rework: `site_url`/`document_library`/`excel_folder`/`sync_schedule` replace hardcoded `site_id`/`drive_id`, dynamic Site ID/Drive ID resolution, `healthCheck()` returns `ConnectionStatus`, `sharepoint:test` diagnostic command |
| P3.8 | Diagnostics deepened: full untruncated Graph response capture, JWT claim decoding, used to confirm a real tenant's `roles` claim was empty pending admin consent |

**All of this was removed** in the "Company AI endpoint" refactor below -
IT now owns SharePoint, Excel sync, and data ingestion entirely, behind
their own HTTP endpoint. Every class, config, migration, and test from
this phase was deleted; nothing about it remains in the running
application. Preserved here, and in git history, for context only.

## Company AI endpoint refactor (complete)

**Laravel is now only an AI client.** The company IT department took
ownership of SharePoint, Excel synchronization, data ingestion, and the
AI model (including Ollama) entirely, exposing one HTTP endpoint. See
[`ai-client.md`](ai-client.md) for the full pipeline and class-by-class
explanation.

| Task | Description |
|---|---|
| R.1 | Removed the entire SharePoint stack: services, contract, exception, DTOs, Action, repository, model, enum, both console commands, config, migration (rolled back cleanly), tests, docs |
| R.2 | Removed `OllamaClient`/`OllamaUnavailableException`/`config/ollama.php`. Added `AIClient` (implements the existing `LLMClient` contract unchanged), `AIServiceUnavailableException`, `config/ai.php` |
| R.3 | `AppServiceProvider`: `LLMClient` → `AIClient`. Dashboard collapsed from 4 status cards (SharePoint/Ollama/Database/Authentication) to 3 (AI Service/Database/Authentication) - `SystemStatusData`, `GetSystemStatusAction`, `dashboard.blade.php` |
| R.4 | Updated/added tests: new `AIClientTest`, `ChatTest` repointed at the new request/response shape, `GetSystemStatusActionTest`/`SystemStatusDataTest`/`DashboardTest` updated for the 3-card layout |
| R.5 | Docs (`ai-client.md` replaces `ollama-api.md`; `sharepoint.md`/`sharepoint-setup.md` deleted), `architecture.md`/this file rewritten, final validation, a single commit |

The chat pipeline itself - `ChatService`, `ChatAction`, `ChatController`,
the Blade UI - needed **zero changes**: it already depended on the
`LLMClient` interface, not `OllamaClient` concretely, so swapping the
bound implementation was the entire fix. This is the payoff of the
Dependency Inversion seam established back in Phase 2.

## What's next

Nothing is currently planned beyond this refactor. Any future data
ingestion, RAG, or document-retrieval work belongs to the company AI
service, not this Laravel application.
