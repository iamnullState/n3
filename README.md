# N3 Core

![Core version 0.2.0](https://img.shields.io/badge/core-0.2.0-2457D6)
![Next decision Phase 11B](https://img.shields.io/badge/next_decision-Phase_11B-8A3FFC)
![PHP 8.5 or newer](https://img.shields.io/badge/PHP-%3E%3D8.5-777BB4?logo=php&logoColor=white)
![MariaDB 11.8 and 12.3](https://img.shields.io/badge/MariaDB-11.8_%7C_12.3-003545?logo=mariadb&logoColor=white)
![Tests 268 passing](https://img.shields.io/badge/tests-268_passing-2EA44F)
![License proprietary](https://img.shields.io/badge/license-proprietary-lightgrey)

N3 is a framework-free PHP modular-monolith foundation for an independently installed, white-label CMS.

## Current milestone

Phase 11A adds a fail-closed Apache/LiteSpeed shared-hosting deployment baseline on top of the protected browser installer. The Secure Core Kernel provides:

- validated environment configuration;
- a single public entry point;
- request, router, controller, response, and view layers;
- a neutral, accessible `Hello, world` fallback that a published CMS Home Page can replace;
- safe HTML escaping and baseline response security headers;
- structured error logging with request identifiers;
- safe 404 and 500 views;
- PHPUnit coverage for the request lifecycle, routing, escaping, and landing page.

The PDO/MariaDB and identity foundations are implemented. Phase 4 adds the first CMS Page slice. Phase 5 establishes trusted module boot, durable synchronization/jobs, private resource and transport contracts, and preview-first forward module migrations. Phase 6 adds opt-in aggregate Analytics and a data-free local `stdio` MCP server. Phase 7 adds a disabled-by-default Media module with secure image ingestion and lifecycle controls. Phase 8 adds an idempotent default-site scaffold, audited white-label identity, editable Page navigation, and published Home routing with a safe fallback. Phase 9 adds a disabled-by-default Blog module with secure administrator authoring and bounded public retrieval.

Latest verification on 2026-09-02: host PHP passed 268 tests/783 assertions; MariaDB 11.8 passed 268/1,226 and MariaDB 12.3 passed 268/1,180. Environment-dependent skips are documented in the runbook.

## Requirements

- PHP 8.5 or newer
- Composer 2
- PHP extensions: PDO and mbstring; `pdo_mysql` for live MariaDB use; GD with JPEG/PNG/WebP plus `fileinfo` when Media is enabled
- Docker with Compose for the live MariaDB integration suite, or host PHP `pdo_mysql` plus a disposable MariaDB test database

## Setup

```bash
composer install
```

Configuration is read from real environment variables. `.env.example` documents the current keys; the application does not parse `.env` files or commit secrets.

`EMAIL_VERIFICATION_REQUIRED=false` is available only for local/test debugging. Production rejects disabled verification.

`DB_TABLE_PREFIX` defaults to empty. A non-empty value must be 2–24 lowercase ASCII characters, begin with a letter, and end with `_` (for example, `client_`). Set it before the first migration and never change it afterward. N3 records it in installation state and rejects a mismatch.

Fresh installations enter the neutral `/install` workflow before normal application composition. Configure the hosting environment, authorize with the independent one-time installer token, review the read-only preflight, run migrations, and create the first administrator. Completion is persisted in MariaDB and a private lock; installer routes then close and redirect to `/login`. Production requires removal of installer and migration credentials from the web runtime. See `docs/INSTALLATION.md`.

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
See `docs/INSTALLATION.md` for shared-hosting preflight, browser setup, completion, and recovery.
See `docs/DEPLOYMENT.md` for the Apache/LiteSpeed hosting contract, production checks, release sequence, and recovery boundary.
See `docs/IDENTITY.md` for registration, verification, session, rate-limit, and local-outbox behavior.
See `docs/CONTENT.md` for the Page model, lifecycle, authorization, routes, audit behavior, and quality gates.
See `docs/MODULES.md` for the in-process trust model, service and event contracts, module manifest/lifecycle, failure semantics, and deferred external boundaries.
See `docs/JOBS.md` for durable payload, claim, lease, retry, dead-letter, and operator-recovery semantics.
See `docs/API.md` for the versioned JSON envelope, liveness route, and deferred authentication, pagination, rate-limit, and idempotency contracts.
See `docs/WEBHOOKS.md` for signing, replay defense, endpoint restrictions, delivery retry classification, and deferred network boundaries.
See `docs/ANALYTICS.md` for the opt-in aggregate metric boundary, module deployment, reporting, retention, and deferred tracking features.
See `docs/MCP.md` for the local protocol, trust, tool, rate-limit, enablement, and deferred capability boundaries.
See `docs/MEDIA.md` for secure image ingestion, private storage, conditional Page delivery, lifecycle controls, backup, and deferred generalized attachment boundaries.
See `docs/SITE.md` for scaffold installation, site identity, navigation, public Home behavior, and recovery.
See `docs/BLOG.md` for Blog enablement, content lifecycle, routes, security boundaries, migration, and recovery.
