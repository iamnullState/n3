# Webhook Security and Delivery Contract

Phase 5C defines signing, verification, replay persistence, endpoint policy, and retry classification. It deliberately provides no inbound webhook route and no network-capable outbound transport. No external service can call or be called by this code yet.

## Signature contract

Each delivery uses these headers:

- `X-N3-Webhook-ID`: unique 16–100 character delivery identifier;
- `X-N3-Webhook-Timestamp`: positive Unix timestamp;
- `X-N3-Webhook-Signature`: `v1=` followed by a lowercase SHA-256 HMAC.

The signed bytes are exactly:

```text
{timestamp}.{delivery_id}.{exact_request_body}
```

Signing secrets must contain at least 32 bytes. Verification validates header shapes, accepts at most 300 seconds of past or future clock skew, computes the HMAC over the exact received bytes, and compares it in constant time. Authentication failures expose only a controlled failure.

Clock synchronization is an operational requirement. Secrets must come from protected deployment configuration, must not be logged, and need a separately designed overlap/rotation workflow before production webhook enablement.

## Replay defense

After a signature is valid, the verifier SHA-256 hashes the delivery ID and atomically inserts a receipt keyed by source and delivery hash. A duplicate insert fails closed. Invalid signatures do not consume a receipt. Receipts default to a 24-hour lifetime and may be pruned only after expiry.

Migration `202608310006_create_webhook_receipts` stores no body, signature, secret, URL, or raw delivery ID. The source key is a controlled integration identifier, not user input or personal data.

## Outbound destination policy

Future destinations must use an exact allowlisted HTTPS hostname on port 443. URLs containing credentials, fragments, IP literals, unlisted hosts, or nonstandard ports are rejected.

This URL check is not sufficient by itself to prevent DNS rebinding. A future HTTP transport must resolve and pin approved public addresses, reject private/reserved/link-local destinations after every resolution and redirect, disable unapproved redirects, bound connect/read timeouts and response sizes, and avoid proxy inheritance unless explicitly configured. Until then, network delivery remains disabled.

## Delivery and retry policy

- Any `2xx` response is successful and is not retried.
- `408`, `425`, `429`, and `5xx` responses are retryable.
- Other `4xx` responses are permanent failures.
- Transport failures will be represented by controlled failure codes without copying response bodies or exception text into audit records.

Future delivery work will use the Phase 5B durable job queue for bounded attempts, leases, exponential delay, dead-letter state, and operator recovery. A stable delivery ID must be reused for retries of the same logical delivery. Payload minimization, destination ownership, event schemas, audit retention, secret rotation, and per-endpoint concurrency limits must be approved before enabling a producer.

## Recovery

Expired replay receipts can be removed in bounded maintenance work. Operators must not delete unexpired receipts to make a failed call pass. If clocks drift, fix time synchronization rather than widening the verification window without review. If a signing secret is exposed, disable the integration, preserve sanitized evidence, rotate through an approved overlap procedure, and investigate receipts and delivery audits.
