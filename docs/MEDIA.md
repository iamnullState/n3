# Private Media Library

Phase 7A adds `n3/media`, a disabled-by-default administrator image-ingestion module. It proves a secure upload, sanitization, private-storage, catalog, and preview boundary. It is not yet a public asset delivery system or a Page attachment workflow.

## Enablement and deployment

The module requires PHP GD with JPEG, PNG, and WebP support, `fileinfo`, the normal MariaDB runtime/migration credentials, and a `SECURITY_HASH_KEY` of at least 32 bytes. The Docker test runtime includes these extensions.

```bash
export MEDIA_ENABLED=true
php bin/n3 module:migrate
# Take and verify a backup, review the plan, then:
php bin/n3 module:migrate --apply
php bin/n3 module:sync
php bin/n3 module:sync --apply
```

Only an authenticated `admin` can access `GET /admin/media`, submit `POST /admin/media`, or retrieve `GET /admin/media/{id}/preview`. Member access is denied, anonymous access redirects to login, HTML responses use `no-store`, and previews use a short private cache plus `X-Robots-Tag: noindex, nofollow`.

## Ingestion contract

- Accept JPEG and PNG only. Client MIME types, filenames, extensions, and image dimensions are not trusted.
- Reject PHP upload failures, unreadable/non-regular files, symbolic links, empty files, malformed containers, appended polyglot payloads, decoder failures, unsupported types, and nested file shapes.
- Default bounds are 10 MiB source size, 25 megapixels, and 12,000 pixels on either axis. Deployment overrides remain bounded by `MediaConfig`.
- Decode the pixels and re-encode a metadata-free WebP master at quality 85 plus a maximum-480-pixel WebP preview at quality 78. The raw source is never copied into N3 storage.
- Apply JPEG EXIF orientation before encoding when EXIF support is available. No source metadata is copied to the derivative.
- Assign a cryptographically random 128-bit lowercase identifier; do not preserve or store the client filename.
- Store masters under private `storage/modules/n3/media/data/assets/` and previews under private `storage/modules/n3/media/cache/previews/`. Module storage rejects traversal and links, uses atomic writes, and requests `0700` directories and `0600` files.
- If catalog creation fails after files are written, remove the exact master and preview. There is no recursive cleanup operation.

## Catalog, rate limit, and audit boundary

The forward-only module migration owns three prefixed tables:

- assets: random public ID, administrator label, sanitized dimensions, WebP byte size, master SHA-256, and creation timestamp;
- upload limits: HMAC-SHA-256 subject hash, fixed-hour bucket, and attempt count;
- events: controlled event key, optional random asset ID, and occurrence timestamp.

The upload limit defaults to 20 attempts per trusted `REMOTE_ADDR` per hour. Forwarded headers remain untrusted. IP addresses are HMAC-hashed with `SECURITY_HASH_KEY` before persistence. Audit keys are limited to `upload_succeeded`, `upload_rejected`, and `upload_rate_limited`. Raw IPs, original filenames, source paths, request payloads, image metadata, and source bytes are not stored or logged.

## Configuration

| Variable | Default | Purpose |
| --- | ---: | --- |
| `MEDIA_ENABLED` | `false` | Adds the reviewed module to the deployment allowlist. |
| `MEDIA_MAX_UPLOAD_BYTES` | `10485760` | Maximum raw upload bytes. |
| `MEDIA_MAX_PIXELS` | `25000000` | Maximum decoded width × height. |
| `MEDIA_MAX_DIMENSION` | `12000` | Maximum width or height. |
| `MEDIA_MAX_PROCESSED_BYTES` | `12582912` | Maximum private master size. |
| `MEDIA_PREVIEW_MAX_DIMENSION` | `480` | Preview bounding box. |
| `MEDIA_UPLOADS_PER_HOUR` | `20` | Fixed-window attempts per HMAC subject. |
| `MEDIA_WEBP_QUALITY` | `85` | Sanitized master quality. |
| `MEDIA_PREVIEW_WEBP_QUALITY` | `78` | Preview quality. |

PHP/web-server request limits must allow the configured raw upload size. Keep `post_max_size` above `upload_max_filesize`, but do not use those settings as the application’s only size control.

## Backup and recovery

Back up the MariaDB catalog and `storage/modules/n3/media/data/` together from the same maintenance window. The cache previews are reproducible in principle but no regeneration command exists in Phase 7A, so include them in operational backups for now. A database row without its corresponding private file returns a controlled 503 and records only an exception class in the application log. A file without a catalog row is not addressable through the preview route.

Do not edit applied migration source, delete module history, or destructively roll back after Media data exists. Recover failed DDL with schema inspection and a reviewed forward repair. Disabling the module removes its runtime routes but intentionally retains its catalog and private files.

## Deferred work

Page attachment, public delivery, deletion/replacement, derivative regeneration, quotas and retention, bulk upload, cropping, galleries, SVG/GIF/AVIF, video, and externally backed object storage are outside Phase 7A. Each requires a separate authorization, lifecycle, privacy, and recovery contract.
