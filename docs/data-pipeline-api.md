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
- Cleans and structures the data into MySQL in plain PHP - never sends a
  raw Excel file to the AI
- Calls Ollama **directly** (`{OLLAMA_BASE_URL}/api/generate`), with no
  wrapper API in between

No SharePoint API integration, no Azure, no vector database/embeddings.

## Hybrid architecture

```
Excel (.xlsx/.xls/.xlsm/.ods/.csv)
  -> PHP: clean, structure, calculate  (SyncSourcesAction)
  -> stored as structured JSON          (DataRecord)
  -> LLM: interpret, analyze, answer    (ChatDataService + OllamaClient)
```

PHP is responsible for everything that must be exact and deterministic -
parsing, type detection, normalization, averages/min/max. The LLM is used
**only** for interpretation, insight, and free-form answers - it's told
explicitly not to invent values and to quote the provided numbers rather
than recompute them.

## Data model

**`sources`** - admin-configured pointers to Excel files (managed via
`/admin/sources`):

| Column | Purpose |
|---|---|
| `department_id` | FK to `departments` (admin-managed via `/admin/departments`) |
| `name` | human-readable label |
| `type` | `file` (already-synced local path) or `url` (downloaded fresh on every sync) |
| `file_path` | required when `type = file`, e.g. `D:\data\quality\report.xlsx` |
| `url` | required when `type = url` |
| `last_synced_at` | updated after every successful sync |

**`data_records`** - one row per Source, holding that source's entire
cleaned dataset. Replaced wholesale on every sync, not accumulated day by
day:

| Column | Purpose |
|---|---|
| `source_id` | unique FK to `sources` - one dataset per source |
| `department` | denormalized department slug, for `/api/chat`'s query |
| `columns` | JSON list of detected column names, in sheet order |
| `rows` | JSON list of `{column: value}` maps - the cleaned data, any structure |
| `summary` | JSON `{rows_count, averages: {col: avg}, min: {col: min}, max: {col: max}}` - numeric columns only |
| `synced_at` | when this dataset was last (re)synced |

There is **no fixed schema** and **no required date column** - a source
can be a quality report, an inventory list, a purchasing sheet, anything.
A department can have multiple Sources, each contributing its own dataset
row; `/api/chat` sends the AI all of a department's datasets together.

## Excel format

The one structural assumption: **row 0 is a header row.** Beyond that,
column layout, order, and count are all arbitrary - `SyncSourcesAction`
infers each column's type once (not per cell, see below), not from a
fixed position:

| Detected type | Rule |
|---|---|
| `date` | header name contains "date" (case-insensitive) |
| `numeric` | every non-blank value in the column parses as a number |
| `text` | everything else |

Type is inferred **per column**, not per cell, deliberately: a
"Quantity" column containing `45000` would otherwise get misread as a
plausible-looking Excel date serial if each cell were judged on its own.

Values are normalized defensively - never throws, always skips instead:

- **date** columns: numeric cells are treated as Excel date serials
  (via PhpSpreadsheet's date helper); everything else goes through
  `Carbon::parse()`. Unparseable → `null`, not a failure.
- **numeric** columns: non-numeric cells → `null`.
- **text** columns: trimmed; invalid UTF-8 is repaired so the value is
  always safe to `json_encode()` later.
- A row is skipped entirely if every recognized column is blank, or if
  it's an exact repeat of the header row (a common quirk in
  printed/exported reports where the header reappears mid-sheet).
- A row where every cell failed to normalize to something non-null is
  also skipped.

## Sync pipeline

```
Source (file or url)
  -> SyncSourcesAction: resolve a local path
       - type=file: use file_path directly (must already exist)
       - type=url: download via Http::get() to a temp file matching
         the URL's real extension (so the right reader gets picked)
  -> parse with maatwebsite/excel (RawRowsImport, positional row access)
  -> detect each column's type from the header row
  -> clean every data row (skip blanks/duplicate-header rows/all-null rows)
  -> compute rows_count/averages/min/max over numeric columns
  -> DataRecord::updateOrCreate(['source_id'], [columns, rows, summary, ...])
  -> Source::update(['last_synced_at' => now()])
  -> (url-type only) delete the temp file
```

Triggered by `php artisan sources:sync`, scheduled every 10 minutes via
`routes/console.php`. One source's failure (missing file, failed
download, unparseable file) is logged to the `chatbot` channel and
skipped - it never aborts the rest of the sync run, and the command
itself always exits `0` (a per-source failure isn't a scheduler-level
failure). A bad individual *row* never fails a sync either - it's just
skipped; see "Excel format" above.

## Chat pipeline

```
POST /api/chat {department, message}
  -> ChatController: validate department (must exist in `departments`) + message
  -> DataRecord::where(department)->latest(synced_at)->get()
  -> ChatDataService::buildPrompt(datasets, message)
       - fixed system prompt (see below)
       - + "DATA:\n" + json_encode({datasets: [{source, columns, rows, summary, synced_at}, ...]})
       - + "QUESTION:\n{message}"
  -> OllamaClient::generate(prompt)
       -> POST {OLLAMA_BASE_URL}/api/generate {model, prompt, stream: false, think: false}
       <- {"response": "..."}
  <- {"answer": "..."}  (or 503 {"error": "..."} if Ollama is unreachable)
```

`department` is validated against the admin-managed `departments` table,
not a fixed list - a department added via `/admin/departments` is
immediately askable here too, once it has at least one synced source.

System prompt (`ChatDataService::SYSTEM_PROMPT`, sent verbatim):

```
You are a data analyst AI.
You receive structured JSON data extracted from Excel files.

Your job:
- Understand the dataset
- Analyze trends, averages, anomalies
- Answer the user's question clearly

Rules:
- Use ONLY the provided data
- Do NOT invent values
- If data is missing or unclear, say so
- Be concise and accurate
```

Rate-limited to 20 requests/minute per client (`throttle:20,1`) - not
explicitly requested, but added since this endpoint is public/
unauthenticated and each call is a real, non-trivial LLM inference cost.
Remove the middleware in `routes/api.php` if undesired.

## Classes

| Class | Responsibility |
|---|---|
| `App\Models\Source` | one configured Excel source |
| `App\Models\DataRecord` | one Source's cleaned, structured dataset |
| `App\Models\Department` | admin-managed department (name/slug) |
| `App\ValueObjects\Department` | legacy fixed enum - still used only by `Api\SourceController`'s external `POST /api/sources` contract, kept unchanged for backward compatibility |
| `App\Actions\SyncSourcesAction` | resolves, parses, cleans, and stores one source's dataset |
| `App\Imports\RawRowsImport` | `maatwebsite/excel` import, positional row access, no heading row |
| `App\Console\Commands\SyncSources` | `sources:sync` - thin, loops sources, isolates per-source failures |
| `App\Services\ChatDataService` | builds the Ollama prompt (system prompt + JSON datasets + question) |
| `App\Services\OllamaClient` | raw HTTP client for Ollama's `/api/generate` |
| `App\Http\Controllers\Api\ChatController` | `POST /api/chat` |
| `App\Http\Controllers\Api\SourceController` | `GET`/`POST /api/sources` |

## Configuration

| Env var | Default | Purpose |
|---|---|---|
| `OLLAMA_BASE_URL` | `http://10.10.10.15:11434` | Ollama server address |
| `OLLAMA_MODEL` | `qwen2.5:9b` | Model to use for generation |
| `OLLAMA_TIMEOUT` | `120` | Per-request timeout, in seconds |

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

- `GET /` (`resources/views/public-dashboard.blade.php`) - department
  buttons (from the `departments` table) linking to `/chat/{slug}`.
- `GET /chat/{slug}` (`resources/views/chat.blade.php`) - a chat page for
  that department; 404s for an unknown slug. Plain JS `fetch()` posts to
  `POST /api/chat` and renders the answer as a chat bubble, with a
  loading state and an "Error getting response" bubble on failure. No
  build step, no framework - inline `<script>` in the Blade file.

## Testing

All tests mock Ollama via `Http::fake()` and the url-type source download
the same way - no test makes a real network call. File-type source tests
generate a real temporary Excel file with PhpSpreadsheet directly (the
same library `maatwebsite/excel` is built on), so the actual parsing path
is genuinely exercised, not mocked. See `tests/Unit/Actions/SyncSourcesActionTest.php`
(including cases with no date column at all, a numeric column that looks
like a plausible date serial, and an unparseable date cell), `tests/Unit/Services/OllamaClientTest.php`,
`ChatDataServiceTest.php`, `tests/Feature/Api/ChatControllerTest.php`,
`SourceControllerTest.php`, and `tests/Feature/Console/SyncSourcesTest.php`.
