# Durable Jobs

Phase 5B provides a small MariaDB-backed job boundary for work that should not execute inside a synchronous HTTP event listener. It is an at-least-once execution system: handlers must be idempotent and safe to repeat.

## Data model

`jobs` stores a stable owning module ID, job type, bounded JSON payload, optional hashed idempotency key, status, attempts, availability time, lease metadata, controlled failure code, and completion timestamps. `job_events` records controlled lifecycle events without duplicating payloads or exception messages.

Statuses are:

```text
pending → running → succeeded
   ↑          |
   |          ├→ pending (bounded retry)
   |          └→ dead
   |
dead ─────────┘ (explicit operator retry resets attempts)
```

Payloads are limited to 64 KiB before insertion and must encode as JSON. They must not contain passwords, tokens, session IDs, raw credentials, or unnecessary personal data. Idempotency keys are SHA-256 hashed before storage and are unique per module; enqueuing the same key returns the original job ID and leaves its original payload unchanged.

## Claim and lease semantics

Workers select one available pending job using a transaction and `FOR UPDATE SKIP LOCKED`, then increment its attempt and assign a random 256-bit lease token. Completion, retry, or dead-letter updates require that exact current token. This prevents a stale worker from completing a job after its lease was recovered.

Leases default to five minutes and may be 1–3600 seconds. An expired lease is requeued when attempts remain and dead-lettered at the maximum attempt. Recovery is safe to run concurrently because rows are locked and skipped.

A lease is not a hard PHP execution timeout. Phase 5B executes only one job per process; the deployment supervisor must impose a process timeout shorter than the lease. Heartbeats and lease renewal are deferred. Because a worker can fail after performing a side effect but before marking success, handlers must use idempotency at the destination or a transactional local write.

## Handler failures

- Success marks the job `succeeded`.
- `RetryableJobFailure` uses a controlled code and a 1–3600 second requested delay.
- `PermanentJobFailure` immediately dead-letters the job.
- Any other throwable becomes `handler_exception`; its message and trace are not stored in job state.
- Missing handlers become `unknown_handler` dead letters.
- Retryable failures become dead when the current attempt reaches `max_attempts`.

Unhandled exceptions use bounded exponential delays starting at 30 seconds. A job supports 1–25 total attempts.

## Operations

Apply Core migrations and synchronize modules before running workers.

```bash
php bin/n3 jobs:status
php bin/n3 jobs:work --once
php bin/n3 jobs:recover
php bin/n3 jobs:recover --apply
php bin/n3 jobs:retry --id=123 --force
```

`jobs:status` reports counts only and returns nonzero when dead jobs or expired leases require attention. `jobs:recover` previews expired leases unless `--apply` is supplied. Retrying a dead job is an explicit operator action, resets attempts, and is audited. Operators must inspect the handler and downstream side effects before retrying; the CLI intentionally does not print payloads.

Phase 5B does not include an enqueue CLI, infinite worker loop, scheduler, recurring jobs, concurrency manager, web UI, payload viewer, pruning, or retention automation. `n3/core-probe:probe` is a no-side-effect handler used only to validate the contract.
