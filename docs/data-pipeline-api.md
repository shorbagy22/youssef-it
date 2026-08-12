# Data Pipeline & Chat API

How Excel data gets from a local file (or a URL) into MySQL, and how the
`/api/chat` endpoint uses it to answer department-scoped questions via
Ollama. See [`architecture.md`](architecture.md) for how this fits
alongside the rest of the app.

## Scope

This is a **separate, independent pipeline** from the web `/chat` feature
(`ChatController`/`ChatAction`/`ChatService`/`AIClient`), which talks to a
different, IT-owned AI API wrapper. This one:

- Reads Excel files Laravel does **not** own the syncing of (already
  synced to a local path by some other process, e.g. `D:\data\quality\report.xlsx`)
- Extracts structured data (NRFT, PPM, defects) into MySQL - never sends
  a raw Excel file to the AI
- Calls Ollama **directly** (`http://10.10.10.15:11434/api/generate`),
  with no wrapper API in between

No SharePoint API integration, no Azure, no vector database/embeddings.
Excel is the only supported format; departments are a fixed set
(`quality`, `it`, `safety`, `maintenance`).

## Data model

**`sources`** - admin-configured pointers to Excel files:

| Column | Purpose |
|---|---|
| `department` | one of the four known departments |
| `name` | human-readable label |
| `type` | `file` (already-synced local path) or `url` (downloaded fresh on every sync) |
| `file_path` | required when `type = file`, e.g. `D:\data\quality\report.xlsx` |
| `url` | required when `type = url` |
| `last_synced_at` | updated after every successful sync |

**`data_records`** - the structured, parsed output. One row per
department per day:

| Column | Purpose |
|---|---|
| `department` | matches the source's department |
| `date` | one Excel row = one day's record |
| `nrft` | decimal, nullable |
| `ppm` | decimal, nullable |
| `defects` | JSON array of strings, e.g. `["scratch", "dent"]` |
| `extra_data` | JSON, reserved/unused for now |

`(department, date)` is unique - `SyncSourcesAction` upserts on this pair,
so re-running a sync updates existing rows instead of duplicating them.

## Excel format

Fixed, positional columns, row 0 always assumed to be a header and
skipped:

| Column index | Meaning |
|---|---|
| 0 | date |
| 1 | nrft |
| 2 | ppm |
| 3 | defects, comma-separated (e.g. `scratch,dent`) |

Dates are parsed defensively: a numeric cell is treated as an Excel date
serial (converted via PhpSpreadsheet's date helper); anything else is
parsed with `Carbon::parse()`. Blank rows (empty date) are skipped.

## Sync pipeline

```
Source (file or url)
  -> SyncSourcesAction: resolve a local path
       - type=file: use file_path directly (must already exist)
       - type=url: download via Http::get(), write to a temp file
  -> parse with maatwebsite/excel (RawRowsImport, positional access)
  -> DataRecord::updateOrCreate(['department', 'date'], [...])
  -> Source::update(['last_synced_at' => now()])
  -> (url-type only) delete the temp file
```

Triggered by `php artisan sources:sync`, scheduled every 10 minutes via
`routes/console.php`. One source's failure (missing file, failed
download, unparseable content) is logged to the `chatbot` channel and
skipped - it never aborts the rest of the sync run, and the command
itself always exits `0` (a per-source failure isn't a scheduler-level
failure).

## Chat pipeline

```
POST /api/chat {department, message}
  -> ChatController: validate department (must be a known Department) + message
  -> DataRecord::where(department)->orderByDesc(date)->limit(10)->get()
  -> ChatDataService::buildPrompt(records, message)
       - formats each record as "Date: {date}\nNRFT: {nrft}\nPPM: {ppm}\nDefects: {defects}",
         blank line between records
       - wraps as: "You are a factory assistant.\n\nAnswer based ONLY on this
         data:\n\n{context}\n\nQuestion:\n{message}"
  -> OllamaClient::generate(prompt)
       -> POST {OLLAMA_BASE_URL}/api/generate {model, prompt, stream: false}
       <- {"response": "..."}
  <- {"answer": "..."}  (or 503 {"error": "..."} if Ollama is unreachable)
```

Rate-limited to 20 requests/minute per client (`throttle:20,1`) - not
explicitly requested, but added since this endpoint is public/
unauthenticated and each call is a real, non-trivial LLM inference cost.
Remove the middleware in `routes/api.php` if undesired.

## Classes

| Class | Responsibility |
|---|---|
| `App\Models\Source` | one configured Excel source |
| `App\Models\DataRecord` | one department-day's structured data |
| `App\ValueObjects\Department` | backed enum of the four valid department values |
| `App\Actions\SyncSourcesAction` | resolves, parses, and upserts one source |
| `App\Imports\RawRowsImport` | `maatwebsite/excel` import, positional row access, no heading row |
| `App\Console\Commands\SyncSources` | `sources:sync` - thin, loops sources, isolates per-source failures |
| `App\Services\ChatDataService` | builds the Ollama prompt from a department's recent records |
| `App\Services\OllamaClient` | raw HTTP client for Ollama's `/api/generate` |
| `App\Http\Controllers\Api\ChatController` | `POST /api/chat` |
| `App\Http\Controllers\Api\SourceController` | `GET`/`POST /api/sources` |

## Configuration

| Env var | Default | Purpose |
|---|---|---|
| `OLLAMA_BASE_URL` | `http://10.10.10.15:11434` | Ollama server address |
| `OLLAMA_MODEL` | `qwen2.5:9b` | Model to use for generation |
| `OLLAMA_TIMEOUT` | `60` | Per-request timeout, in seconds |

## Example requests

**Register a file-type source:**

```bash
curl -X POST http://localhost:8000/api/sources \
  -H "Content-Type: application/json" \
  -d '{
    "department": "quality",
    "name": "Daily Quality Report",
    "type": "file",
    "file_path": "D:\\data\\quality\\report.xlsx"
  }'
```

Response: `{"message": "Source created"}` (201) - `store()` returns a plain
confirmation, not the created record.

**Register a url-type source:**

```bash
curl -X POST http://localhost:8000/api/sources \
  -H "Content-Type: application/json" \
  -d '{
    "department": "it",
    "name": "IT Metrics",
    "type": "url",
    "url": "https://internal.example.com/it-report.xlsx"
  }'
```

**List sources:**

```bash
curl http://localhost:8000/api/sources
```

**Run a sync manually** (normally handled by the scheduler):

```bash
php artisan sources:sync
```

**Ask the chatbot a question:**

```bash
curl -X POST http://localhost:8000/api/chat \
  -H "Content-Type: application/json" \
  -d '{
    "department": "quality",
    "message": "What is NRFT in May?"
  }'
```

Response:

```json
{"answer": "NRFT in May was 95.5%, based on the most recent data available."}
```

## Public UI

Two unauthenticated Blade pages sit in front of this pipeline, separate
from the authenticated `/dashboard` and `/chat` (web `/chat` pipeline)
routes:

- `GET /` (`resources/views/public-dashboard.blade.php`) - four
  department buttons linking to `/chat/{department}`.
- `GET /chat/{department}` (`resources/views/chat.blade.php`) - a chat
  page for that department; 404s for anything not in `App\ValueObjects\Department`.
  Plain JS `fetch()` posts to `POST /api/chat` and renders the answer as
  a chat bubble, with a loading state and an "Error getting response"
  bubble on failure. No build step, no framework - inline `<script>` in
  the Blade file.

## Testing

All tests mock Ollama via `Http::fake()` and the url-type source download
the same way - no test makes a real network call. File-type source tests
generate a real temporary `.xlsx` file with PhpSpreadsheet directly (the
same library `maatwebsite/excel` is built on), so the actual parsing path
is genuinely exercised, not mocked. See `tests/Unit/Actions/SyncSourcesActionTest.php`,
`tests/Unit/Services/OllamaClientTest.php`, `ChatDataServiceTest.php`,
`tests/Feature/Api/ChatControllerTest.php`, `SourceControllerTest.php`,
and `tests/Feature/Console/SyncSourcesTest.php`.
