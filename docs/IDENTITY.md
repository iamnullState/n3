# Identity and Access Foundation

Phase 3 is delivered as two reviewed slices. Slice 3A provides verified public registration. Slice 3B provides authentication, private server-side sessions, password recovery, and protected administrator bootstrap.

## Registration and verification

Public registrations always receive `role_key=member` and `account_status=pending_verification`. Browser input cannot select roles or status. A valid one-time verification token changes the account to `active` and records `email_verified_at`.

Verification tokens are 32 random bytes encoded for URLs. MariaDB stores only their SHA-256 hashes. Tokens expire after 24 hours by default, are single-use, and older unused tokens are revoked when a new one is issued. The initial email link is captured into the private session and redirected to a clean confirmation URL before the state-changing POST.

Duplicate registration and resend requests return generic messages. MariaDB-backed fixed-window limits apply independently to HMAC-hashed email and IP subjects. Only `REMOTE_ADDR` is trusted; proxy headers are ignored until a deployment defines trusted proxies.

## Local message delivery

`IDENTITY_MAIL_DRIVER=local_outbox` writes verification and reset messages under `storage/outbox/`, outside the public document root. The directory uses mode `0700` and messages use `0600` where POSIX modes are supported. Messages contain bearer links and must be treated as secrets.

The local outbox is forbidden when registration is enabled in production. A production notification adapter is required before production registration can be enabled.

## Configuration

| Variable | Default | Rule |
| --- | --- | --- |
| `APP_URL` | `http://127.0.0.1:8000` | Absolute origin without trailing slash. Production must use HTTPS. |
| `SECURITY_HASH_KEY` | none | Required; at least 32 random bytes; used for keyed subject hashes. |
| `REGISTRATION_ENABLED` | `false` | Explicit feature gate. |
| `IDENTITY_MAIL_DRIVER` | `local_outbox` | Local/test only until another adapter is implemented. |
| `EMAIL_VERIFICATION_TTL` | `86400` | Between 5 minutes and 7 days. |
| `PASSWORD_RESET_TTL` | `1800` | Reset token lifetime; 5 minutes to 24 hours. |
| `SESSION_IDLE_TTL` | `1800` | Idle session lifetime; 5 minutes to 24 hours. |
| `SESSION_ABSOLUTE_TTL` | `43200` | Absolute lifetime; at least the idle lifetime and at most 7 days. |

Use an environment secret manager outside local development. Do not commit `.env` files or reuse `SECURITY_HASH_KEY` between installations.

## Routes

- `GET /register` and `POST /register`
- `GET /verify-email?token=...` captures a token and redirects to a clean URL
- `GET /verify-email` displays verification and resend forms
- `POST /verify-email` consumes the captured token
- `POST /verify-email/resend` requests a replacement message with a generic response
- `GET /login` and `POST /login`
- `POST /logout`
- `GET /account` requires an active authenticated account
- `GET /forgot-password` and `POST /forgot-password`
- `GET /reset-password?token=...` captures a token and redirects to a clean URL
- `POST /reset-password` consumes the captured token and changes the password

Every state-changing route requires a session-bound CSRF token. Invalid CSRF returns `419`. Invalid form data returns `422`; successful POSTs use `303` redirects. Authentication and recovery responses do not reveal whether an email belongs to an account. A pending account is identified only after its correct password is supplied.

## Authentication and sessions

Login always performs `password_verify()`, using a constant fallback password hash when no user exists. Only active, email-verified accounts may authenticate. Password hashes are upgraded on login when PHP's current default changes.

Sessions use PHP's native file handler under private `storage/sessions/`. Strict mode and cookie-only identifiers are enabled. Cookies are `HttpOnly`, `SameSite=Lax`, and must be `Secure` in production. Authentication rotates the session identifier and CSRF secret. Sessions expire after 30 idle minutes or 12 absolute hours by default.

Each user has a `session_version`. A status or authority workflow must increment it. Password reset does so automatically, invalidating all previously authenticated sessions on their next request. The current authorities are fixed to `admin` and `member`; public forms never accept either authority or account status.

## Password recovery

Reset tokens use the same 32-byte URL-safe generation and SHA-256-at-rest pattern as verification tokens. They expire after 30 minutes, are single-use, and all outstanding reset tokens are revoked after a successful reset. Request and completion attempts are rate-limited using HMAC-hashed subjects. The browser captures a raw bearer token into private session state and immediately redirects to a URL without the token.

## Administrator bootstrap

Apply migrations and take a backup before bootstrapping the first administrator. Then run:

```bash
php bin/n3 admin:create --name="Site Administrator" --email=admin@example.test
```

The interactive password is hidden. For controlled automation, pipe exactly one password line and add `--password-stdin`. Passwords supplied through a `--password=...` argument are rejected because process arguments can be exposed. The command creates one active, verified `admin` and refuses to create another administrator or reuse an existing email.

## Maintenance

```bash
php bin/n3 identity:prune
```

The command deletes expired/used verification and reset tokens, stale rate-limit buckets, local outbox messages older than seven days, and security events older than 90 days. Retention is an initial operational default and must be reviewed against future audit/privacy requirements.

Security events contain controlled event/outcome codes, optional user IDs, request IDs, and HMAC hashes of email/IP subjects. They never contain raw emails, passwords, bearer tokens, or request payloads.
