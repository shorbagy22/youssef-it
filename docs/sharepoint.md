# SharePoint Integration

How the chatbot authenticates against SharePoint, what Microsoft Graph
endpoints it calls, and how Excel files get synced into MySQL. See
[`architecture.md`](architecture.md) for the broader layering rules this
pipeline follows, and [`project-roadmap.md`](project-roadmap.md) for what
Phase 4/5 build on top of this.

## Scope

SharePoint is treated as a **structured data source**, not a generic
document store: the only supported file type is Excel (`.xlsx`/`.xls`).
Phase 3 downloads and tracks these files - it does **not** parse their
contents, run any AI/RAG over them, or handle PDFs/Word documents. That's
intentionally left for later phases (see below).

## Authentication

Microsoft Graph is called using the **OAuth2 client credentials (app-only)
flow** - there's no interactive user sign-in, since this is a background
sync job, not something a user triggers per-request.

1. An Azure AD app registration is created for this application, with a
   client secret generated for it.
2. `MicrosoftGraphClient` POSTs to
   `https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token` with
   `grant_type=client_credentials`, `scope=https://graph.microsoft.com/.default`,
   and the configured `client_id`/`client_secret`.
3. The response's `access_token` is used as a Bearer token on every
   subsequent Graph request, and is **memoized in memory for that
   `MicrosoftGraphClient` instance's lifetime only** (with a 30-second
   expiry buffer) - nothing is written to a cache store, a file, or the
   database. Each new sync run (a fresh command invocation) fetches its
   own token.

All three credentials - `tenant_id`, `client_id`, `client_secret` - live in
`config/sharepoint.php`, populated entirely from `.env`. **Never hardcode
these values or log the client secret or access token**; only IDs
(site/drive/document) and non-secret metadata are logged (see "Logging"
below).

## Permissions

The Azure AD app registration needs an **application permission** (not
delegated, since there's no signed-in user) granted **admin consent** by a
tenant administrator:

- `Files.Read.All` (or `Sites.Read.All` if broader read access across
  sites is needed) - read-only is sufficient, since this integration only
  ever downloads files, never writes back to SharePoint.

Without admin consent, every Graph call returns `403 Forbidden` even with
a valid token - if `sharepoint:sync-excel` fails immediately with an auth
or permission error, check this first before assuming a code bug.

## Microsoft Graph API

Base URL: `https://graph.microsoft.com/v1.0`. Every call is scoped to the
drive (document library) configured in `config('sharepoint.drive_id')` -
resolve this once via Graph's site/drive lookup endpoints when setting up
a new environment, then set it in `.env`.

| Endpoint | Used by | Purpose |
|---|---|---|
| `GET /drives/{drive_id}` | `healthCheck()` | Confirm the drive is reachable with the current credentials, without listing or downloading anything |
| `GET /drives/{drive_id}/root/children` | `listChildren()` | List items in the drive's root folder |
| `GET /drives/{drive_id}/root:/{folder_path}:/children` | `listChildren()` | List items in a specific subfolder, when `sharepoint.folder_path` is set |
| `GET /drives/{drive_id}/items/{item_id}` | `getItemMetadata()` | Fetch one item's metadata directly (not currently used by the sync flow, but exposed for future use) |
| `GET /drives/{drive_id}/items/{item_id}/content` | `downloadContent()` | Download one file's raw bytes |

Each driveItem Graph returns carries a `file` facet (for actual files) or
a `folder` facet (for folders) - `SharePointExcelService` uses this to
skip folders, then filters remaining files by extension to keep only
`.xlsx`/`.xls`.

## Folder structure

**In SharePoint**: `config('sharepoint.site_id')` identifies the site,
`drive_id` identifies the document library within it, and the optional
`folder_path` (e.g. `Reports/Daily`) scopes the sync to a specific
subfolder instead of the whole library root. Leave `folder_path` empty to
sync everything in the library's root folder.

**Locally**: downloaded files are stored on the `local` filesystem disk
(`storage/app/private/`, per `config/filesystems.php`) under
`sharepoint-excel/{sharepoint_id}.xlsx` - named by SharePoint's own item
ID rather than the original filename, so renames in SharePoint don't
orphan a locally-stored file or create duplicates.

## The sync pipeline

```
SharePoint (Excel files)
    -> Microsoft Graph API
    -> MicrosoftGraphClient (auth + generic Graph HTTP calls)
    -> SharePointExcelService (implements ExcelFileProvider; filters to Excel, returns DTOs)
    -> SyncSharePointExcelFilesAction (orchestrates: list, compare, download, store)
    -> SyncedDocumentRepository -> MySQL (synced_documents table) + local disk (raw file bytes)
```

Triggered by the `sharepoint:sync-excel` artisan command, scheduled daily
in `routes/console.php` (matching how often the source files themselves
change) - not by an HTTP request, since this is a background job with no
per-user trigger.

### Change detection

For each remote file, `SyncSharePointExcelFilesAction` compares Graph's
`lastModifiedDateTime` against the stored `synced_documents.modified_at`:

- **No existing row, or timestamps differ** → download the file, compute
  a SHA-256 checksum of its bytes, store the bytes to disk, and upsert the
  row (`file_name`, `modified_at`, `checksum`, `sync_status = synced`,
  `local_path`, `size`, `synced_at`).
- **Timestamps match** → skip entirely; no download, no write.

The remote timestamp is the primary, cheap signal (no download needed to
check it) - the checksum is computed only after downloading and stored
as an integrity record, not used to detect changes on its own (that would
require downloading every file on every run just to hash it).

A failed download (SharePoint unreachable, item deleted mid-sync, etc.)
marks that row `sync_status = failed` and the run continues with the
remaining files - one bad file never aborts the whole sync. The next
run retries it automatically, since a `Failed` row's `modified_at` is
left untouched, so it still won't match the remote timestamp.

## Classes

### `App\Services\MicrosoftGraphClient`

The only class that knows Graph's authentication flow, URL shapes, and
JSON format. Generic - has no idea what an "Excel file" is. Retries
transient failures (`->retry(2, 250)`, same pattern as `OllamaClient`)
and throws `SharePointException` once retries are exhausted, on a bad
auth response, or on an unexpected response shape.

### `App\Services\SharePointExcelService`

Implements `ExcelFileProvider`. Built directly on `MicrosoftGraphClient`
(Graph is the only supported source, so no further abstraction between
them is needed). Filters `listChildren()`'s raw items down to Excel files
only and converts them to `SharePointExcelFile` DTOs - callers never see
Graph's JSON shape. Logs site ID, drive ID, document ID, download
duration, and file size around every download, to the `chatbot` log
channel - never the file's content or any credential.

### `App\Contracts\ExcelFileProvider`

The interface `SyncSharePointExcelFilesAction` depends on, not
`SharePointExcelService` directly - the Dependency Inversion seam that
keeps the sync logic testable (mocked in
`SyncSharePointExcelFilesActionTest`) and would let a different Excel
source be substituted without touching the Action. Bound to
`SharePointExcelService` in `AppServiceProvider::register()`.

### `App\Actions\SharePoint\SyncSharePointExcelFilesAction`

The orchestrator described above. Business logic only - no HTTP, no
console I/O. Returns a `SyncResult` DTO summarizing the run.

### `App\Repositories\SyncedDocumentRepository`

Wraps every Eloquent query against `synced_documents` - `findBySharePointId()`,
`markSynced()`, `markFailed()`. The Action never touches the `SyncedDocument`
model or its column names directly, only this repository's intent-revealing
methods.

### `App\Models\SyncedDocument` / `App\ValueObjects\SyncStatus`

The Eloquent model backing `synced_documents`, and the backed enum
(`Pending`, `Synced`, `Failed`) its `sync_status` column casts to -
matching the `ConnectionStatus` pattern already used for the dashboard.

### `App\DTOs\SharePointExcelFile` / `App\DTOs\SyncResult`

Immutable data carriers. `SharePointExcelFile` is one remote file's
metadata before download (id, name, modified time, size).
`SyncResult` summarizes a completed run (checked/synced/skipped/failed
counts, plus per-file error messages) for the console command and tests
to report on.

### `App\Exceptions\SharePointException`

Thrown by `MicrosoftGraphClient` and `SharePointExcelService` on any
unreachable/failed/malformed Graph interaction. Caught specifically by
`SyncSharePointExcelFilesAction` (per-file, so one failure doesn't abort
the run) and by the console command (if listing files itself fails,
before any per-file loop even starts).

### `App\Console\Commands\SyncSharePointExcelFiles`

The `sharepoint:sync-excel` artisan command - thin, delegates entirely to
the Action, prints a summary, and exits non-zero if anything failed.

## Configuration

| Env var | Purpose |
|---|---|
| `SHAREPOINT_TENANT_ID` | Azure AD tenant ID |
| `SHAREPOINT_CLIENT_ID` | App registration (client) ID |
| `SHAREPOINT_CLIENT_SECRET` | App registration client secret - never logged |
| `SHAREPOINT_SITE_ID` | Graph site ID hosting the document library |
| `SHAREPOINT_DRIVE_ID` | Graph drive ID (document library) to sync from |
| `SHAREPOINT_FOLDER_PATH` | Optional subfolder to scope the sync to; empty = drive root |

## Running the sync

```bash
php artisan sharepoint:sync-excel
```

Scheduled to run automatically once a day via `routes/console.php`'s
`Schedule::command(...)->daily()` - requires the Laravel scheduler to be
running (`php artisan schedule:work` in development, or a system cron
entry calling `php artisan schedule:run` every minute in production).

## Testing

All tests mock Microsoft Graph via Laravel's `Http::fake()` - no test
makes a real network call, and none require real Azure AD credentials.
See `tests/Unit/Services/MicrosoftGraphClientTest.php` and
`SharePointExcelServiceTest.php` for the HTTP-level cases,
`tests/Unit/Actions/SharePoint/SyncSharePointExcelFilesActionTest.php` for
the orchestration logic (new/unchanged/changed/failed files), and
`tests/Feature/Console/SyncSharePointExcelFilesTest.php` for the command
end-to-end.

## What's next

Per the standing "no AI, no RAG, Excel only" constraint, Phase 3
deliberately stops at syncing raw files and metadata:

- **Phase 4** adds an Excel Parser (reads the synced `.xlsx` files),
  Database Import, and Data Normalization - turning raw spreadsheet rows
  into structured, queryable data in MySQL.
- **Phase 5** connects the chatbot to that normalized MySQL data instead
  of querying SharePoint directly per chat request - the `ChatService`
  pipeline from Phase 2 gains a data-lookup step ahead of prompt building.
