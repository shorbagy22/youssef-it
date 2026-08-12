# Development

## Requirements

- PHP 8.4.1 through 8.4.x. The exact lockfile combines Symfony packages
  requiring PHP 8.4.1+ with PhpSpreadsheet 1.x, which requires PHP < 8.5.
- Composer 2
- Node 22.20+ and npm (compatible with the exact Vite lockfile)
- MySQL 8+

## First-time setup

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env` to a local
MySQL database and user (not root), then:

```bash
php artisan migrate
```

## Running the app

```bash
composer run dev
```

Runs the Laravel dev server, queue listener, Pail log watcher, and Vite dev
server concurrently. Visit `http://localhost:8000`.

## Testing

Tests use [Pest](https://pestphp.com) and run against an in-memory SQLite
database (configured in `phpunit.xml`, independent of whatever's in
`.env`).

```bash
php artisan test
php artisan test --compact   # quieter output, used in CI
```

- `tests/Feature` — HTTP-level tests (routes, auth, full request/response
  cycles), bound to `RefreshDatabase` via `tests/Pest.php`.
- `tests/Unit` — plain unit tests for individual classes (enums, DTOs,
  Actions) with no HTTP or database involvement.

## Code style

[Laravel Pint](https://laravel.com/docs/pint) enforces the `laravel` preset
plus `declare_strict_types` (every file must declare strict types),
`no_unused_imports`, and alphabetically sorted imports — configured in
`pint.json`.

```bash
php vendor/bin/pint          # auto-fix
php vendor/bin/pint --test   # check only, no changes (used in CI)
```

## Static analysis

[PHPStan](https://phpstan.org) via [Larastan](https://github.com/larastan/larastan)
runs at **level 8** (max strictness) against `app/`.

```bash
php vendor/bin/phpstan analyse
```

`phpstan-baseline.neon` holds a fixed set of pre-existing findings in
unmodified Breeze-generated auth code (see the comment at the top of that
file for why). New code is held to level 8 with no baseline entries —
don't add to the baseline to silence a finding in code you wrote; fix it
instead.

## Continuous integration

`.github/workflows/ci.yml` runs Pint, PHPStan, and Pest on every push and
pull request against `main`, using PHP 8.4 and
an `.env` copied fresh from `.env.example` — no database service container
is needed since tests run against in-memory SQLite regardless of the
`DB_*` values in `.env`.

## Conventions

- **Clean Architecture layering** — see [`architecture.md`](architecture.md).
  Controllers never contain business logic; that lives in Actions.
- **Dependency injection everywhere** — services are constructor-injected
  and resolved through Laravel's container, never manually instantiated
  with `new` outside of tests/factories.
- **`declare(strict_types=1)`** at the top of every PHP file.
- **PHPDoc on every public class** describing its purpose and, where
  non-obvious, its place in the architecture.
- **One Phase 1 commit; one commit per milestone after that** — Phase 1
  itself shipped as a single commit ("Initial Laravel enterprise chatbot
  foundation"); every milestone after Phase 1 gets its own commit.
