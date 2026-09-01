# Aggregate Analytics

Phase 6A is N3's first functional optional module. It measures aggregate application request outcomes without tracking visitors.

## Privacy boundary

Analytics is disabled by default. When `ANALYTICS_ENABLED=true`, Core classifies each completed request before it reaches the module. The module receives only:

- a UTC occurrence time;
- one controlled route category: `public.home`, `public.page`, `identity`, `admin.pages`, `api.system`, `api.other`, or `other`;
- the normalized HTTP method and response status;
- bounded request duration in microseconds.

The metric contract has no field for a raw path, query string, page slug, IP address, user agent, referrer, cookie, visitor/account/session/request identifier, payload, or fingerprint. There is no browser beacon or Analytics JavaScript. Route parameters and unknown paths collapse into fixed categories before observation.

## Storage and availability

The `n3/analytics` module owns one deterministic `hourly_metrics` table through its forward-only module migration. Its primary key is the UTC hour, route category, method, and status code. Each request performs one atomic upsert that increments the count, total duration, and maximum duration. Individual request rows are never stored.

The runtime connection is lazy: module bootstrap does not connect to MariaDB. A failed metric write records only the controlled `request_metrics_failed` event, exception class, and environment. It never changes the HTTP response. Operators should therefore monitor this event because request availability intentionally takes priority over analytics completeness.

The default retention target is 90 days. Aggregate rows still reveal traffic volume and operational patterns, so backups, database access, exports, and command output remain private operational data.

## Enable and deploy

Set `ANALYTICS_ENABLED=true` in the deployment environment, then follow the standard reviewed module sequence:

```bash
php bin/n3 migrate
php bin/n3 module:migrate
# after a verified backup and review
php bin/n3 module:migrate --apply
php bin/n3 module:sync
php bin/n3 module:sync --apply
php bin/n3 module:migrate:status
php bin/n3 module:status
```

Do not enable request collection before its module migration is applied. The application remains available if this is misordered, but it logs a sanitized metric failure for every request and stores no metrics.

## Reporting and retention

Reporting is CLI-only in Phase 6A:

```bash
php bin/n3 analytics:summary
php bin/n3 analytics:summary --days=30
php bin/n3 analytics:prune
php bin/n3 analytics:prune --days=90 --apply
```

Both day ranges must be between 1 and 365. Summary output groups the requested window by the controlled category, method, and status; it reports request count, average duration, and maximum duration. Pruning previews the number of hourly rows and changes nothing unless `--apply` is explicit.

## Deferred work

An administrator dashboard, browser Web Vitals, page-level analytics, campaign attribution, consent-dependent tracking, visitor/session analytics, raw events, cookie identifiers, exports, and external analytics providers are outside Phase 6A. Each requires its own privacy, authorization, retention, and availability review.
