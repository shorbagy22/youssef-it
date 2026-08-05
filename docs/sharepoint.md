# SharePoint Integration

How the chatbot authenticates against SharePoint, what Microsoft Graph
endpoints it calls, and how Excel files get synced into MySQL. See
[`architecture.md`](architecture.md) for the broader layering rules this
pipeline follows, [`project-roadmap.md`](project-roadmap.md) for what
Phase 4/5 build on top of this, and
[`sharepoint-setup.md`](sharepoint-setup.md) for the step-by-step runbook
to actually turn this on.

## Scope

SharePoint is treated as a **structured data source**, not a generic
document store: the only supported file type is Excel (`.xlsx`/`.xls`).
This integration downloads and tracks these files - it does **not** parse
their contents, run any AI/RAG over them, or handle PDFs/Word documents.
That's intentionally left for later phases (see below).

## Configuration-only setup

Every value this integration needs lives in `config/sharepoint.php`,
populated entirely from `.env` - **nothing is hardcoded, and no code
change is ever required to configure a new environment**. In particular,
the Graph **Site ID** and **Drive ID** are never configured directly:
`MicrosoftGraphClient` resolves both dynamically at runtime from
`SHAREPOINT_SITE_URL` and `SHAREPOINT_DOCUMENT_LIBRARY` (see "Dynamic
resolution" below). Filling in `.env` is the entire setup process - see
[`sharepoint-setup.md`](sharepoint-setup.md).

If `SHAREPOINT_SITE_URL` is empty, the integration behaves as
**unconfigured** everywhere, deliberately without throwing:
`healthCheck()` returns `ConnectionStatus::NotConfigured`, the dashboard's
SharePoint card shows "Not Configured", `sharepoint:sync-excel` prints
"SharePoint is not configured." and exits `0`, and `sharepoint:test` does
the same. No Graph call is attempted at all in this state.

## Authentication

Microsoft Graph is called using the **OAuth2 client credentials (app-only)
flow** - there's no interactive user sign-in, since this is a background
sync job, not something a user triggers per-request.

1. An Azure AD app registration is created for this application, with a
   client secret generated for it (see `sharepoint-setup.md` Step 2).
2. `MicrosoftGraphClient` POSTs to
   `https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token` with
   `grant_type=client_credentials`, `scope=https://graph.microsoft.com/.default`,
   and the configured `client_id`/`client_secret`.
3. The response's `access_token` is used as a Bearer token on every
   subsequent Graph request, and is **memoized in memory for that
   `MicrosoftGraphClient` instance's lifetime only** (with a 30-second
   expiry buffer) - nothing is written to a cache store, a file, or the
   database. Each new command invocation fetches its own token.

**Never hardcode `tenant_id`/`client_id`/`client_secret`, or log the
client secret or access token**; only IDs (site/drive/document) and
non-secret metadata are logged (see "Logging" below).

## Permissions

The Azure AD app registration needs an **application permission** (not
delegated, since there's no signed-in user) granted **admin consent** by a
tenant administrator:

- `Files.Read.All` (or `Sites.Read.All` if broader read access across
  sites is needed) - read-only is sufficient, since this integration only
  ever downloads files, never writes back to SharePoint.

Without admin consent, every Graph call returns `403 Forbidden` even with
a valid token - if `sharepoint:test` reports a connection failure, check
this first before assuming a code bug.

## Dynamic resolution

Given `SHAREPOINT_SITE_URL=https://contoso.sharepoint.com/sites/TeamSite`:

1. **Site ID**: `MicrosoftGraphClient::resolveSiteId()` parses the URL
   into a hostname (`contoso.sharepoint.com`) and server-relative path
   (`/sites/TeamSite`), then calls
   `GET /sites/{hostname}:{path}` (or `GET /sites/{hostname}` for a root
   site with no path) to get Graph's Site ID.
2. **Drive ID**: `resolveDriveId()` calls `GET /sites/{site_id}/drives` to
   list every document library on the site, then matches one against
   `SHAREPOINT_DOCUMENT_LIBRARY` by its `name` field. If nothing matches
   by name, it falls back to matching by `webUrl` instead - **Graph's
   default document library is internally named `"Documents"`, even
   though SharePoint's UI (and this config's default) calls it `"Shared
   Documents"`** - the webUrl fallback is what makes the common default
   value actually resolve.

Both IDs are memoized in memory per `MicrosoftGraphClient` instance, same
as the access token - resolved once per command run, reused for every
subsequent call.

## Microsoft Graph API

Base URL: `https://graph.microsoft.com/v1.0`.

| Endpoint | Used by | Purpose |
|---|---|---|
| `GET /sites/{hostname}:{path}` | `resolveSiteId()` | Resolve `SHAREPOINT_SITE_URL` to a Graph Site ID |
| `GET /sites/{site_id}/drives` | `resolveDriveId()`, `listDocumentLibraries()` | List document libraries on the site, to find the configured one (or list all, for diagnostics) |
| `GET /drives/{drive_id}/root/children` | `listChildren()` | List items in the document library's root folder |
| `GET /drives/{drive_id}/root:/{excel_folder}:/children` | `listChildren()` | List items in the configured Excel subfolder, when `sharepoint.excel_folder` is set |
| `GET /drives/{drive_id}/items/{item_id}` | `getItemMetadata()` | Fetch one item's metadata directly (not currently used by the sync flow, but exposed for future use) |
| `GET /drives/{drive_id}/items/{item_id}/content` | `downloadContent()` | Download one file's raw bytes |

Each driveItem Graph returns carries a `file` facet (for actual files) or
a `folder` facet (for folders) - `SharePointExcelService` uses this to
skip folders, then filters remaining files by extension to keep only
`.xlsx`/`.xls`.

**Testing gotcha**: paths containing spaces (e.g. an Excel folder named
`"Daily Reports"`) get percent-encoded by the HTTP client when the actual
request is built. `Http::fake()` patterns in tests must match the encoded
form (`Daily%20Reports`), not the literal space, or the fake won't match
and the test will attempt (and fail) a real network call.

## Folder structure

**In SharePoint**: `SHAREPOINT_SITE_URL` identifies the site,
`SHAREPOINT_DOCUMENT_LIBRARY` identifies the document library within it
(resolved dynamically - see above), and the optional
`SHAREPOINT_EXCEL_FOLDER` (e.g. `"Daily Reports"`) scopes the sync to a
specific subfolder instead of the whole library root. Leave it empty to
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
    -> MicrosoftGraphClient (auth + site/drive resolution + generic Graph HTTP calls)
    -> SharePointExcelService (implements ExcelFileProvider; filters to Excel, returns DTOs)
    -> SyncSharePointExcelFilesAction (orchestrates: list, compare, download, store)
    -> SyncedDocumentRepository -> MySQL (synced_documents table) + local disk (raw file bytes)
```

Triggered by the `sharepoint:sync-excel` artisan command, scheduled via
`routes/console.php`'s `Schedule::command(...)->cron(config('sharepoint.sync_schedule'))`
(default: daily at 2am, configurable via `SHAREPOINT_SYNC_SCHEDULE` - a
raw cron expression, no code change needed to adjust the cadence) - not
by an HTTP request, since this is a background job with no per-user
trigger.

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

Before any of this, the Action checks `healthCheck()` first: if
SharePoint isn't configured, it returns a `SyncResult` with
`notConfigured: true` and does nothing else - no listing, no downloading,
no exceptions.

## Classes

### `App\Services\MicrosoftGraphClient`

The only class that knows Graph's authentication flow, URL shapes, and
JSON format. Generic - has no idea what an "Excel file" is. Retries
transient failures (`->retry(2, 250)`, same pattern as `OllamaClient`)
and throws `SharePointException` once retries are exhausted, on a bad
auth response, or on an unexpected response shape. `healthCheck()` is the
one method that never throws - it returns `ConnectionStatus::NotConfigured`,
`::Connected`, or `::Disconnected`.

### `App\Services\SharePointExcelService`

Implements `ExcelFileProvider`. Built directly on `MicrosoftGraphClient`
(Graph is the only supported source, so no further abstraction between
them is needed). Filters `listChildren()`'s raw items down to Excel files
only and converts them to `SharePointExcelFile` DTOs - callers never see
Graph's JSON shape. Logs the resolved site ID, drive ID, document ID,
download duration, and file size around every download, to the `chatbot`
log channel - never the file's content or any credential.

### `App\Contracts\ExcelFileProvider`

The interface `SyncSharePointExcelFilesAction` and
`GetSystemStatusAction` depend on, not `SharePointExcelService` directly -
the Dependency Inversion seam that keeps both testable (mocked in their
respective tests) and would let a different Excel source be substituted
without touching either. Bound to `SharePointExcelService` in
`AppServiceProvider::register()`.

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
metadata before download (id, name, modified time, size). `SyncResult`
summarizes a completed run (checked/synced/skipped/failed counts, a
`notConfigured` flag, plus per-file error messages) for the console
command and tests to report on.

### `App\Exceptions\SharePointException`

Thrown by `MicrosoftGraphClient` and `SharePointExcelService` on any
unreachable/failed/malformed Graph interaction. Caught specifically by
`SyncSharePointExcelFilesAction` (per-file, so one failure doesn't abort
the run) and by both console commands (if listing files itself fails,
before any per-file loop even starts). Never thrown for the "not
configured" state - that's handled explicitly via `ConnectionStatus`.

### `App\Console\Commands\SyncSharePointExcelFiles`

The `sharepoint:sync-excel` artisan command - thin, delegates entirely to
the Action, prints a summary, and exits non-zero if anything failed (zero
if merely unconfigured).

### `App\Console\Commands\TestSharePointConnection`

The `sharepoint:test` artisan command - a diagnostic that authenticates,
resolves the site, and lists document libraries and Excel files found,
without syncing anything into MySQL. Meant to be run once after setup to
confirm the configuration is correct. See
[`sharepoint-setup.md`](sharepoint-setup.md) Step 5.

## Configuration

| Env var | Default | Purpose |
|---|---|---|
| `SHAREPOINT_TENANT_ID` | — | Azure AD tenant ID |
| `SHAREPOINT_CLIENT_ID` | — | App registration (client) ID |
| `SHAREPOINT_CLIENT_SECRET` | — | App registration client secret - never logged |
| `SHAREPOINT_SITE_URL` | *(empty)* | Full SharePoint site URL; empty means unconfigured |
| `SHAREPOINT_DOCUMENT_LIBRARY` | `Shared Documents` | Document library name to sync from |
| `SHAREPOINT_EXCEL_FOLDER` | `Daily Reports` | Subfolder within the library containing the Excel files |
| `SHAREPOINT_SYNC_SCHEDULE` | `0 2 * * *` | Cron expression for how often `sharepoint:sync-excel` runs |

## Running the sync

```bash
php artisan sharepoint:test        # diagnostic - confirm setup is correct
php artisan sharepoint:sync-excel  # the real sync
```

Scheduled to run automatically via `routes/console.php`'s
`Schedule::command(...)->cron(...)` - requires the Laravel scheduler to be
running (`php artisan schedule:work` in development, or a system cron
entry calling `php artisan schedule:run` every minute in production).

## Testing

All tests mock Microsoft Graph via Laravel's `Http::fake()` - no test
makes a real network call, and none require real Azure AD credentials.
See `tests/Unit/Services/MicrosoftGraphClientTest.php` and
`SharePointExcelServiceTest.php` for the HTTP-level cases (including site
and drive resolution, the webUrl fallback, and the not-configured path),
`tests/Unit/Actions/SharePoint/SyncSharePointExcelFilesActionTest.php` for
the orchestration logic (new/unchanged/changed/failed files, plus not
configured), and `tests/Feature/Console/SyncSharePointExcelFilesTest.php`
and `TestSharePointConnectionTest.php` for both commands end-to-end.

## What's next

Per the standing "no AI, no RAG, Excel only" constraint, this integration
deliberately stops at syncing raw files and metadata:

- **Phase 4** adds an Excel Parser (reads the synced `.xlsx` files),
  Database Import, and Data Normalization - turning raw spreadsheet rows
  into structured, queryable data in MySQL.
- **Phase 5** connects the chatbot to that normalized MySQL data instead
  of querying SharePoint directly per chat request - the `ChatService`
  pipeline from Phase 2 gains a data-lookup step ahead of prompt building.
