# CompanyAIChatbot

An enterprise chatbot that answers questions using the company's own SharePoint
documents, powered by a locally-hosted Ollama model. Built with Laravel 12,
Blade, and Bootstrap 5.

## Status

**Phase 2 — Local Ollama chatbot.** The app now has a working chat feature
backed by a local Ollama server (`POST /api/generate`) — see
[`docs/ollama-api.md`](docs/ollama-api.md) for the pipeline and
[`docs/project-roadmap.md`](docs/project-roadmap.md) for what's next.
SharePoint document retrieval is designed but **not yet built**; every
chat answer today comes from the model alone, with no company documents
grounding it.

## Requirements

- PHP 8.3+
- Composer 2
- Node 20+ and npm
- MySQL 8+
- [Ollama](https://ollama.com), running locally with a model pulled (e.g.
  `ollama pull llama3.1`), to use the chat feature

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

- [`docs/architecture.md`](docs/architecture.md) — Clean Architecture layering and the planned SharePoint/Ollama pipeline
- [`docs/ollama-api.md`](docs/ollama-api.md) — the Ollama HTTP API and every class in the chat pipeline
- [`docs/development.md`](docs/development.md) — local dev workflow, tooling, and how to run checks
- [`docs/project-roadmap.md`](docs/project-roadmap.md) — milestone plan from Phase 1 onward

## Quality checks

```bash
php vendor/bin/pint --test       # code style
php vendor/bin/phpstan analyse   # static analysis (level 8)
php artisan test                 # Pest test suite
```

These also run automatically in CI on every push and pull request against
`main` — see [`.github/workflows/ci.yml`](.github/workflows/ci.yml).
