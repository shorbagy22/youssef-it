# CompanyAIChatbot

An enterprise chatbot that answers employee questions via the company's
centralized AI HTTP endpoint. Laravel is only an AI client - SharePoint
access, Excel synchronization, data ingestion, and the AI model itself are
all owned and operated by IT behind that one endpoint. Built with
Laravel 12, Blade, and Bootstrap 5.

## Status

**Laravel is only an AI client.** The chat feature POSTs a question to
`AI_API_URL` and returns the answer - see
[`docs/ai-client.md`](docs/ai-client.md) for the full pipeline and
[`docs/project-roadmap.md`](docs/project-roadmap.md) for history. There is
no SharePoint code, no Microsoft Graph integration, and no local Ollama
process in this application anymore.

## Requirements

- PHP 8.3+
- Composer 2
- Node 20+ and npm
- MySQL 8+
- Network access to the company's AI HTTP endpoint (`AI_API_URL`) to use
  the chat feature

## Setup

```bash
composer install
npm install
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

- [`docs/architecture.md`](docs/architecture.md) — Clean Architecture layering and the current AI-client-only design
- [`docs/ai-client.md`](docs/ai-client.md) — the company AI HTTP endpoint's request/response shape and every class in the chat pipeline
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
