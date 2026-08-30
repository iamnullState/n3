# Core Services, Events, and Modules

Phase 5A introduces the first executable module boundary. It is intentionally limited to trusted, deployment-installed PHP modules. It does not permit uploaded extensions, remote code, runtime installation, public APIs, webhooks, queues, or scheduled jobs.

## Trust boundary

An enabled in-process module has the same operating-system and PHP privileges as Core. Its source must therefore be reviewed, version-controlled, deployed with the application, and treated as trusted code. N3 does not provide a PHP sandbox.

Code that is tenant-supplied, user-supplied, independently administered, or not trusted with Core secrets and database access must not run as an in-process module. A later external-service boundary will use authenticated, versioned HTTP APIs and webhooks with explicit data minimization and timeouts.

`config/modules.php` is the current deployment-time allowlist. A module present in source but omitted from that file is disabled and is never instantiated by bootstrap. There is no administration-screen toggle or database-backed module state in this slice.

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

Install, update, uninstall, module migrations, persisted enable/disable state, recovery from partial deployment, and compatibility checks across skipped versions remain Phase 5 follow-up work. Until those rules exist, adding or removing a module is a reviewed deployment change with the normal application backup and rollback procedure.

## Service registry

Core creates one explicit `ServiceRegistry`. Core services are registered under stable class/interface identifiers, after which modules may register their own services during `register()`. Duplicate identifiers are rejected. Once all registration completes, the registry is frozen; late mutation is rejected.

The registry is not ambient dependency injection and does not discover classes. Consumers request an explicit identifier and must verify the returned contract. Core must never depend on a service owned by an optional module.

## Events

Events are typed objects dispatched in-process. Modules receive only the listener-registration contract during boot; dispatch and lifecycle sealing remain Core-owned. Listeners declare an event class/interface, module ID, stable listener ID, and integer priority. Higher priorities run first; equal priorities preserve registration order.

Delivery becomes active only after all module listener registration is sealed. It is synchronous, in-memory, once per dispatch attempt, and fail-fast. There is no automatic retry. A listener that performs durable side effects is responsible for transaction placement and idempotency; work requiring retries or isolation belongs in the future job system. The dispatcher wraps failures with controlled attribution and stops remaining listeners.

`CoreStarted` is the only Core lifecycle event in this slice. It contains the Core version and occurrence time. Events must not contain secrets, raw credentials, session identifiers, or unnecessary personal data.

## Sample module

`n3/core-probe` is an enabled, non-functional contract probe. It registers a private status service, marks itself booted, and observes `CoreStarted`. It adds no routes, database tables, files, UI, network calls, or user-facing behavior. Removing it from `config/modules.php` disables it cleanly.

## Remaining Phase 5 boundaries

- durable module state and safe install/update/uninstall workflows;
- module-owned database migrations, storage, configuration, and least-privilege policy;
- jobs with ownership, locks, timeouts, retries, dead letters, and operator recovery;
- public REST authentication, authorization, errors, pagination, rate limits, and idempotency;
- inbound/outbound webhook signing, replay defense, retries, and delivery audit;
- structured startup/event observability beyond controlled exception attribution.
