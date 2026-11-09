# Changelog

All notable changes to this project will be documented in this file.

## 1.3.0 - 2026-11-09

### What's new

#### Fewer remote-disk calls on a cache miss: 8 → 4 (#14, #16)

A miss (`warm()` + `response()` in one request) now does HEAD + HEAD + PUT + PUT. On Cloudflare R2 from Brazil (400–850 ms per call) that is ~2.2 s instead of ~4.2 s.

- The blob is always PUT, with no `exists()` first. The bytes are the same, so overwriting is harmless, and it renews the blob's upload time.
- `.failed` is deleted only when a marker was seen, and `.type` only when the key may be in the legacy layout.
- The `get()`/`response()` right after a successful `warm()` on the same instance serves the bytes just downloaded, without reading the blob back. The copy is used once, so a long-lived instance (Octane) never grows or keeps serving stale bytes.

#### Serve straight from a public bucket / CDN (#15, #17)

- `blobPath(string $key): ?string` returns the blob's disk path without downloading it: no disk call when the pointer is in the Laravel cache, one small read otherwise.
- `url(string $key): ?string` returns the disk's public URL for that blob when the disk has a `url` configured.
- Both return `null` for a key that was never warmed or is still in the legacy layout. Fall back to `response()` / `placeholder()`.

```php
return ($url = $cache->url($slug))
    ? redirect()->away($url)
    : ($cache->response($slug) ?? ImageCache::placeholder());

```
### Upgrade notes

- No code changes needed.
- With `failure_ttl = 0` no `.failed` marker is written any more, since it would never be read.
- **Direct links:** a blob deleted from under its pointer is a broken image, not a placeholder. Prefer `prune()`. If you use a bucket lifecycle rule, it now counts from the latest re-warm, so keep the CDN's redirect cache time well under that window.

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-image-cache/compare/1.2.0...1.3.0

## 1.2.0 - 2026-11-09

### What's new

#### Cap the size of a fetched image (#8, #11)

- New config `image-cache.max_bytes` (`IMAGE_CACHE_MAX_BYTES`, default 10 MB, `0` = unlimited).
- The download is aborted mid-transfer once `Content-Length` or the downloaded bytes pass the limit, so a missing or lying header can't get around it. An oversized image counts as a failed fetch (`.failed` marker + warning).

#### Store images by content hash (#9, #12)

- Bodies are stored once under `<prefix>/blobs/<sha256>`; each key is a small JSON pointer (blob hash + content type). The same image reached through different URLs takes the space of one copy.
- Caches written by older versions (`<key>` + `<key>.type`) are still read and move to the new layout when the key is re-warmed.
- New `prune()` deletes blobs no key points at (blobs younger than an hour are kept). Schedule it, e.g. daily.

#### Fewer storage calls per hit on remote disks (#10, #13)

- A fresh hit (`warm()` + `response()`) now costs a single disk read on S3/R2.
- Each key's "fresh until" time and pointer are kept in the Laravel cache. New config `image-cache.cache_store` (`IMAGE_CACHE_STORE`, `null` = default store, `array` = off). Use a shared store such as Redis when running several servers.
- No more `exists()` round trips before reads.

### Upgrade notes

- No code changes needed. Republish the config to get the new keys (optional, defaults apply).
- Freshness now trusts the Laravel cache: a file aged or deleted by hand on the disk is noticed only when the TTL runs out (a deleted blob is noticed on the next read).
- Old blobs from re-warmed keys pile up until `prune()` runs.

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-image-cache/compare/1.1.0...1.2.0

## 1.1.0 - 2026-10-09

- warm() negative-caches failed fetches for `image-cache.failure_ttl` seconds (default 3600; 0 disables), so a missing upstream image is not re-fetched on every request.
- New `ImageCache::placeholder()`: 1x1 transparent GIF response (with nosniff) for callers that prefer it over a 404.

## 1.0.0 - 2026-09-10

Initial release.

SSRF-safe remote image fetch-and-cache for Laravel: pinned redirect-safe download, image content-type validation, and TTL-based disk caching.

Extracted from duplicated fetch/warm/serve logic in OgImageCache and ReadmeImageCache on jeffersongoncalves.dev.br.

## v1.0.0 - 2026-09-10

Initial release.

SSRF-safe remote image fetch-and-cache for Laravel: pinned redirect-safe download, image content-type validation, and TTL-based disk caching.

Extracted from duplicated fetch/warm/serve logic in OgImageCache and ReadmeImageCache on jeffersongoncalves.dev.br.

## [Unreleased]
