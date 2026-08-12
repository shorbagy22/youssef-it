# CompanyAIChatbot

An enterprise chatbot for a factory, with **two independent chat
pipelines** currently coexisting in this codebase (see Status below).
Built with Laravel 12, Blade, and Bootstrap 5.

## Status

Two separate, independent integrations currently exist side by side -
neither was asked to replace the other, so both remain functional:

1. **Web `/chat` pipeline** - POSTs a question to `AI_API_URL`, a
   company-owned AI HTTP endpoint that itself owns SharePoint access,
   Excel sync, and data ingestion. Laravel never sees structured data
   here. See [`docs/ai-client.md`](docs/ai-client.md).
2. **`/api/chat` data pipeline** - Laravel itself reads Excel files
   already synced to a local path (or downloads one from a URL),
   extracts structured data (NRFT, PPM, defects) into MySQL every 10
   minutes, and calls Ollama **directly** to answer department-scoped
   questions using that data. See
   [`docs/data-pipeline-api.md`](docs/data-pipeline-api.md).

No SharePoint API integration or Microsoft Graph in either pipeline - see
[`docs/project-roadmap.md`](docs/project-roadmap.md) for full history.

## Requirements

- PHP 8.4.1 through 8.4.x (the current lockfile's supported range)
- Composer 2
- Node 22.20+ and npm
- MySQL 8+
- Network access to the company's AI HTTP endpoint (`AI_API_URL`) for the
  web `/chat` pipeline
- Network access to an Ollama server (`OLLAMA_BASE_URL`) for the
  `/api/chat` data pipeline
- `php artisan schedule:work` (dev) or a system cron entry calling
  `php artisan schedule:run` every minute (production), to actually run
  the `sources:sync` job every 10 minutes

## Setup

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your MySQL credentials (`DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`), then run migrations:

```bash
php artisan migrate
```

## Running locally

```bash
composer run dev
```

This starts the Laravel dev server, queue listener, log watcher (Pail), and
Vite dev server together. Visit `http://localhost:8000`.

## Documentation

- [`docs/architecture.md`](docs/architecture.md) — Clean Architecture layering and how both chat pipelines fit together
- [`docs/ai-client.md`](docs/ai-client.md) — the web `/chat` pipeline: company AI HTTP endpoint request/response shape and every class
- [`docs/data-pipeline-api.md`](docs/data-pipeline-api.md) — the `/api/chat` pipeline: Excel sync, structured data, direct Ollama calls, and every class
- [`docs/development.md`](docs/development.md) — local dev workflow, tooling, and how to run checks
- [`docs/project-roadmap.md`](docs/project-roadmap.md) — milestone plan and history from Phase 1 onward

## Quality checks

```bash
php vendor/bin/pint --test       # code style
php vendor/bin/phpstan analyse   # static analysis (level 8)
php artisan test                 # Pest test suite
```

These also run automatically in CI on every push and pull request against
`main` — see [`.github/workflows/ci.yml`](.github/workflows/ci.yml).
