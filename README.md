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

MariaDB, registration, authentication, CSRF tokens, modules, and CMS authoring are intentionally deferred to their own milestones.

## Requirements

- PHP 8.5 or newer
- Composer 2

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
```
