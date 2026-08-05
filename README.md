# CompanyAIChatbot

An enterprise chatbot that answers questions using the company's own SharePoint
documents, powered by a locally-hosted Ollama model. Built with Laravel 12,
Blade, and Bootstrap 5.

## Status

**Phase 1 — Foundation.** This milestone ships the application skeleton only:
authentication (Laravel Breeze), a dashboard with system status cards, the
Clean Architecture folder structure, and full tooling (Pint, PHPStan, Pest,
CI). It does **not** yet include SharePoint, Ollama, or chat functionality —
see [`docs/project-roadmap.md`](docs/project-roadmap.md) for what's next.

## Requirements

- PHP 8.3+
- Composer 2
- Node 20+ and npm
- MySQL 8+

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
