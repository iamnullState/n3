# Identity and Access Foundation

Phase 3 is delivered as two reviewed slices. Slice 3A provides verified public registration. Slice 3B adds authentication, sessions, recovery, and administrator bootstrap.

## Registration and verification

Public registrations always receive `role_key=member` and `account_status=pending_verification`. Browser input cannot select roles or status. A valid one-time verification token changes the account to `active` and records `email_verified_at`.

Verification tokens are 32 random bytes encoded for URLs. MariaDB stores only their SHA-256 hashes. Tokens expire after 24 hours by default, are single-use, and older unused tokens are revoked when a new one is issued. The initial email link is captured into the private session and redirected to a clean confirmation URL before the state-changing POST.

Duplicate registration and resend requests return generic messages. MariaDB-backed fixed-window limits apply independently to HMAC-hashed email and IP subjects. Only `REMOTE_ADDR` is trusted; proxy headers are ignored until a deployment defines trusted proxies.

## Local message delivery

`IDENTITY_MAIL_DRIVER=local_outbox` writes verification messages under `storage/outbox/`, outside the public document root. The directory and messages are restricted to the application account where the filesystem supports POSIX modes. Messages contain bearer links and must be treated as secrets.

The local outbox is forbidden when registration is enabled in production. A production notification adapter is required before production registration can be enabled.

## Configuration

| Variable | Default | Rule |
| --- | --- | --- |
| `APP_URL` | `http://127.0.0.1:8000` | Absolute origin without trailing slash. Production must use HTTPS. |
| `SECURITY_HASH_KEY` | none | Required; at least 32 random bytes; used for keyed subject hashes. |
| `REGISTRATION_ENABLED` | `false` | Explicit feature gate. |
| `IDENTITY_MAIL_DRIVER` | `local_outbox` | Local/test only until another adapter is implemented. |
| `EMAIL_VERIFICATION_TTL` | `86400` | Between 5 minutes and 7 days. |
| `PASSWORD_RESET_TTL` | `1800` | Reserved for Slice 3B. |
| `SESSION_IDLE_TTL` | `1800` | Reserved for Slice 3B. |
| `SESSION_ABSOLUTE_TTL` | `43200` | Reserved for Slice 3B. |

Use an environment secret manager outside local development. Do not commit `.env` files or reuse `SECURITY_HASH_KEY` between installations.

## Routes

- `GET /register` and `POST /register`
- `GET /verify-email?token=...` captures a token and redirects to a clean URL
- `GET /verify-email` displays verification and resend forms
- `POST /verify-email` consumes the captured token
- `POST /verify-email/resend` requests a replacement message with a generic response

Every state-changing route requires a session-bound CSRF token. Invalid CSRF returns `419`. Invalid form data returns `422`; successful POSTs use `303` redirects.

## Maintenance

```bash
php bin/n3 identity:prune
```

The command deletes expired/used verification tokens, stale rate-limit buckets, local outbox messages older than seven days, and security events older than 90 days. Retention is an initial operational default and must be reviewed against future audit/privacy requirements.
