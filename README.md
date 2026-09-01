# N3 Core

N3 is a framework-free PHP modular-monolith foundation for an independently installed, white-label CMS.

## Current milestone

The Secure Core Kernel milestone provides:

- validated environment configuration;
- a single public entry point;
- request, router, controller, response, and view layers;
- a basic N3 landing page based on the external `n3ui` reference;
- safe HTML escaping and baseline response security headers;
- structured error logging with request identifiers;
- safe 404 and 500 views;
- PHPUnit coverage for the request lifecycle, routing, escaping, and landing page.

The PDO/MariaDB and identity foundations are implemented. Phase 4 adds the first CMS Page slice. Phase 5A establishes trusted module boot contracts, and Phase 5B adds preview-first durable module synchronization plus an atomic leased job queue with bounded retry and dead-letter behavior.

## Requirements

- PHP 8.5 or newer
- Composer 2
- PHP extensions: PDO and mbstring; `pdo_mysql` for live MariaDB use
- Docker with Compose for the live MariaDB integration suite, or host PHP `pdo_mysql` plus a disposable MariaDB test database

## Setup

```bash
composer install
```

Configuration is read from real environment variables. `.env.example` documents the current keys; the application does not parse `.env` files or commit secrets.

## Run locally

```bash
APP_ENV=local APP_DEBUG=true php -S 127.0.0.1:8000 -t public
```

Open <http://127.0.0.1:8000/>.

## Validate

```bash
composer test
find bootstrap config public src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

To run the database suite without installing `pdo_mysql` on the host:

```bash
cp .env.docker.example .env.docker
# Replace all three example passwords in .env.docker.
docker compose --env-file .env.docker up -d --wait mariadb
docker compose --env-file .env.docker --profile test build php-test
docker compose --env-file .env.docker --profile test run --rm php-test
```

MariaDB is exposed only at `127.0.0.1:3307`. Stop it without deleting its data with `docker compose --env-file .env.docker down`.

Runtime logs are written to `storage/logs/app.log` and ignored by Git.

## Structure

```text
bootstrap/          Application composition
config/             Validated configuration
public/             Web document root and public assets
resources/views/    PHP views and layouts
src/App/            Application controllers and future use cases
src/Core/           Framework kernel and infrastructure
storage/            Private runtime data
tests/              Unit, feature, and fixture files
database/           Ordered/checksummed schema migrations
docs/               Architecture and operational notes
bin/                Project CLI entry points
```

See `docs/DATABASE.md` for the persistence contract and `docs/DATABASE_SECURITY.md` for database security controls and remaining production gates.
See `docs/IDENTITY.md` for registration, verification, session, rate-limit, and local-outbox behavior.
See `docs/CONTENT.md` for the Page model, lifecycle, authorization, routes, audit behavior, and quality gates.
See `docs/MODULES.md` for the in-process trust model, service and event contracts, module manifest/lifecycle, failure semantics, and deferred external boundaries.
See `docs/JOBS.md` for durable payload, claim, lease, retry, dead-letter, and operator-recovery semantics.
