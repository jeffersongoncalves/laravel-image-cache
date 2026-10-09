# Changelog

All notable changes to this project will be documented in this file.

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
