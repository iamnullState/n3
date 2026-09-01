# Public API Contract

Phase 5C defines the versioned JSON transport boundary without exposing CMS business data. The only reachable endpoint is `GET /api/v1/system/ping`; N3 does not yet issue API credentials or enable authenticated business routes.

## Version and media type

- Public endpoints live below `/api/v1`.
- Breaking request or response changes require a new major route family. Additive fields may be introduced within v1, so clients must ignore fields they do not recognize.
- Responses use `application/json; charset=utf-8`, include `X-N3-API-Version: 1`, and are marked `Cache-Control: no-store`.
- API 404, 405, controlled 4xx, and unexpected 500 responses use the JSON envelope. HTML routes retain the normal HTML error pages.
- CORS is not enabled. Browser access from another origin is not an approved contract.

## Envelopes

Successful responses contain data and metadata:

```json
{
  "data": {"status": "ok"},
  "meta": {"request_id": "request-correlation-id"}
}
```

Errors use stable lowercase codes and controlled messages:

```json
{
  "error": {
    "code": "not_found",
    "message": "Resource not found."
  },
  "meta": {"request_id": "request-correlation-id"}
}
```

Responses never include stack traces, credentials, raw tokens, SQL details, or arbitrary exception text. The request ID may be used to correlate private logs.

## Current route

`GET /api/v1/system/ping` is an unauthenticated process-liveness probe. It returns only `status: ok`; it does not query or reveal database state, versions, configuration, enabled modules, host details, or readiness of external services.

## Deferred authenticated routes

The following are executable Core contracts but have no public route or credential-management workflow yet:

- bearer credentials use the `n3_` token format and are SHA-256 hashed before repository lookup; plaintext bearer tokens must never be persisted or logged;
- principals carry an explicit fixed list of scopes, and routes must enforce the required scope;
- missing or invalid authentication produces `401 unauthenticated`; an authenticated principal lacking a scope produces `403 forbidden`;
- rate limiting is principal-and-route scoped and will produce `429` with a bounded `Retry-After` when a durable implementation is attached;
- every state-changing route will require a 16–128 character `Idempotency-Key`; its identity includes the principal, key, method, path, and hash of the exact request body;
- reused keys with a different request hash must fail rather than execute a second operation.

No API credential issuance, rotation, revocation, durable idempotency store, or API rate-limit repository is approved in this slice. A future business route must provide those pieces and security tests before it is reachable.

## Pagination and input

Collection contracts use opaque cursor pagination. `limit` defaults to 25 and cannot exceed 100. Cursors are opaque URL-safe values no longer than 512 characters. Arrays, malformed numbers, invalid cursors, and limits outside the accepted range fail with `400 invalid_pagination`.

Future JSON mutation routes must reject unsupported media types, malformed JSON, duplicate or ambiguous fields where relevant, unknown authority fields, and bodies beyond a route-specific bound. Signature and idempotency hashes use the exact received body bytes, not re-encoded JSON.

## Operational boundary

The ping endpoint proves only that the PHP application can answer the request. Deployment readiness and dependency health require private operator checks. Do not place secrets in URLs, headers that are logged by infrastructure, response metadata, or request IDs.
