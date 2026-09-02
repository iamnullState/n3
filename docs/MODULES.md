# Core Services, Events, and Modules

Phase 5A introduced the executable module boundary. Phase 5B added deployment-state reconciliation and durable jobs. Phase 5C defined private resources and external transport contracts. Phase 5D added forward-only module migrations and recovery gates. Phase 6A uses those contracts for the opt-in `n3/analytics` module. Phase 6C adds a data-free local `stdio` MCP boundary. All remain limited to trusted, deployment-installed PHP modules. Uploaded extensions, remote code, runtime installation, business APIs, webhook network delivery, and an application-managed daemon remain prohibited.

## Trust boundary

An enabled in-process module has the same operating-system and PHP privileges as Core. Its source must therefore be reviewed, version-controlled, deployed with the application, and treated as trusted code. N3 does not provide a PHP sandbox.

Code that is tenant-supplied, user-supplied, independently administered, or not trusted with Core secrets and database access must not run as an in-process module. A later external-service boundary will use authenticated, versioned HTTP APIs and webhooks with explicit data minimization and timeouts.

`config/modules.php` is the deployment-time runtime allowlist. A module present in source but omitted from that file is disabled and is never instantiated by bootstrap. Phase 5B records the last synchronized state in MariaDB for deployment drift detection and audit, but intentionally does not add a database query to every HTTP bootstrap. There is no administration-screen toggle.

## Manifest contract

Every module implements `N3\Core\Module\Module` and returns an immutable `ModuleManifest` containing:

- a globally unique lowercase `vendor/name` ID;
- a numeric `major.minor.patch` module version;
- an exact Core version or supported `^major.minor` Core constraint;
- required module IDs and their version constraints;
- module IDs that cannot be enabled together.

Phase 5A supports exact versions and a deliberately small caret constraint subset. It does not claim full Composer constraint syntax. Invalid IDs, versions, constraints, duplicate IDs, missing or incompatible dependencies, conflicts, and dependency cycles stop bootstrap before module code executes.

## Lifecycle

Enabled modules run once during application composition:

```text
validate all manifests
        ↓
resolve dependency order
        ↓
register services for every module
        ↓
freeze the service registry
        ↓
boot every module and register listeners
        ↓
seal listener registration
        ↓
dispatch CoreStarted synchronously
```

Dependencies register and boot before their consumers. Registration and listener ordering is deterministic. A throwing `register`, `boot`, or event listener fails closed: the request process does not continue with a partially initialized module graph. Lifecycle exceptions expose the module, phase, event type, and controlled listener identifier without copying arbitrary exception messages into the public error.

## Durable deployment state

Migration `202608300005_create_module_lifecycle_and_jobs` adds `modules` and append-only `module_events`. `module:status` compares the reviewed allowlist with the last synchronized state. `module:sync` previews the same plan and changes nothing unless `--apply` is explicit.

- First synchronization records the module as installed and enabled.
- A higher manifest version produces an audited forward update.
- A missing allowlisted module is retained as disabled; no code, tables, jobs, or files are deleted.
- Re-adding a disabled module enables its retained record.
- Downgrades fail closed.
- Changing dependencies, conflicts, or compatibility without changing the module version fails closed through the manifest hash.
- The complete manifest graph is validated before synchronization.

This registry is deployment evidence, not a live extension marketplace. Lifecycle DML remains transactional, but deployed PHP files and MariaDB DDL are not automatically rolled back.

## Module migrations

A module that owns schema implements the optional `ModuleMigrationProvider` contract. Each returned `ModuleMigration` must:

- be a named, readable, file-backed class within that module's source directory;
- declare the exact owning module ID;
- use a unique `YYYYMMDDHHMM_name` version;
- implement only a forward `up()` operation;
- create and alter only reviewed tables in the module's deterministic schema prefix.

Core validates every definition before applying DDL. Enabled modules are processed in dependency order, with each module's migrations sorted lexically. The exact source file is SHA-256 checksummed and stored in `module_migrations` under the module ID plus migration version. Missing or modified applied source fails closed. Records remain when a module is disabled or removed, and the history table refuses destructive Core rollback once it contains records.

An already synchronized module cannot add a pending migration without increasing its manifest version. New modules and forward module upgrades may apply pending migrations. The module migration runner uses only `DB_MIGRATION_USER`, takes a database-scoped advisory lock, and records completion only after `up()` returns. Modules must never run DDL from `register()`, `boot()`, HTTP, or job handlers.

Deployment order is:

```text
take and verify backup
        ↓
run Core migrate
        ↓
preview module:migrate and module:sync
        ↓
apply module:migrate --apply
        ↓
apply module:sync --apply
        ↓
confirm both status commands are clean
```

MariaDB DDL may commit implicitly. If a migration fails after partially changing schema or succeeds before its history insert fails, stop deployment, inspect the schema and history, and create a reviewed forward repair. Do not retry blindly, edit checksums, mark history manually, or run destructive rollback. Automated down migrations, skipped-version scripts, and destructive uninstall remain prohibited.

## Resource ownership

Module IDs deterministically reserve three resource namespaces:

- private files below `storage/modules/{vendor}/{name}/{data|config|cache}`;
- configuration keys below `modules.{vendor}.{name}.`;
- MariaDB table names beginning with a bounded `m_{readable_id}_{hash}_` prefix.

`ScopedModuleStorage` accepts only validated relative segments, rejects traversal, backslashes, null bytes, symbolic-link ancestors and targets, defaults to a 1 MiB file bound, permits an explicitly configured bound no higher than 24 MiB, writes through an atomic private temporary file, and requests `0700` directories plus `0600` files. Exact-file deletion is available for transactional compensation; recursive deletion is not. The base directory must remain outside `public/`. These filesystem controls separate accidental ownership; trusted in-process PHP is not an operating-system sandbox and can still bypass Core if malicious.

Core does not recursively delete a module namespace. Cache eviction, retained data, export, uninstall, migration recovery, quotas, and backup behavior require explicit future workflows. Module schema prefixes prevent naming collisions but do not provide MariaDB privilege isolation under the shared runtime account.

## Service registry

Core creates one explicit `ServiceRegistry`. Core services are registered under stable class/interface identifiers, after which modules may register their own services during `register()`. Duplicate identifiers are rejected. Once all registration completes, the registry is frozen; late mutation is rejected.

Core registers a lazy `CurrentPrincipalProvider` before module registration. It opens Identity persistence only when a module asks for the current authority, retains normal session expiry/version checks, and returns no display name, email, account ID, session ID, or token. This is an authorization boundary, not a general user-profile service.

The registry is not ambient dependency injection and does not discover classes. Consumers request an explicit identifier and must verify the returned contract. Core must never depend on a service owned by an optional module.

## Events

Events are typed objects dispatched in-process. Modules receive only the listener-registration contract during boot; dispatch and lifecycle sealing remain Core-owned. Listeners declare an event class/interface, module ID, stable listener ID, and integer priority. Higher priorities run first; equal priorities preserve registration order.

Delivery becomes active only after all module listener registration is sealed. It is synchronous, in-memory, once per dispatch attempt, and fail-fast. There is no automatic retry. A listener that performs durable side effects is responsible for transaction placement and idempotency; work requiring retries or isolation belongs in the future job system. The dispatcher wraps failures with controlled attribution and stops remaining listeners.

`CoreStarted` is the only Core lifecycle event in this slice. It contains the Core version and occurrence time. Events must not contain secrets, raw credentials, session identifiers, or unnecessary personal data.

## Sample module

`n3/core-probe` is an enabled, non-functional contract probe. It registers a private status service, marks itself booted, and observes `CoreStarted`. It adds no routes, database tables, files, UI, network calls, or user-facing behavior. Removing it from `config/modules.php` disables it cleanly.

`n3/analytics` is disabled by default and enters the allowlist only when `ANALYTICS_ENABLED=true`. It registers a lazy Core request-metric sink, owns one hourly aggregate table through a module migration, and registers the private `/admin/analytics` route. Core, not the module, converts request paths into a controlled category so raw routes and identifiers never cross the metric boundary. Its dashboard authorizes through `CurrentPrincipalProvider`, which lazily validates the Identity session and exposes only `admin` or `member` authority—no account identifier. See [ANALYTICS.md](ANALYTICS.md).

`n3/mcp-server` is disabled by default and enters the allowlist only when `MCP_ENABLED=true`. It registers a stateless local protocol server, has no migrations, routes, database connection, files, network transport, or background work, and exposes only a constant data-free status tool through an explicit CLI process. See [MCP.md](MCP.md).

`n3/media` is disabled by default and enters the allowlist only when `MEDIA_ENABLED=true`. Version `0.2.0` owns a private image catalog, Page attachment relationship, HMAC-keyed upload limits, sanitized events, private master/preview storage, administrator routes, and attachment-authorized public preview delivery. Page consumes only the optional `PageMediaProvider` application contract, so Core Page schema and behavior remain valid when Media is disabled. GD re-encodes accepted JPEG/PNG pixels to WebP; raw uploads, original filenames, private masters, and internal labels are never publicly delivered. See [MEDIA.md](MEDIA.md).

## Jobs

Phase 5B jobs are documented in [JOBS.md](JOBS.md). `config/jobs.php` is the reviewed handler allowlist. The one-shot CLI worker refuses handlers whose owning module is not enabled.

## Remaining Phase 5 boundaries

- destructive module uninstall, schema rollback, quotas/retention, and finer per-module database privileges;
- worker supervision, heartbeat/lease renewal, hard process timeouts, job pruning, and retention policy;
- API credential issuance and durable API rate-limit/idempotency repositories before any business route;
- inbound webhook routing and outbound delivery/audit after integration ownership and production controls are approved;
- structured startup/event observability beyond controlled exception attribution.
