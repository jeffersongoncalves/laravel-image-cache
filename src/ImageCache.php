<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ImageCache;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\SsrfGuard\SsrfGuard;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * SSRF-safe fetch-and-persist cache for a remote image, keyed by an
 * app-chosen string, on a Laravel disk. Extracted from the near-identical
 * OgImageCache/ReadmeImageCache classes that used to live directly in an
 * app's App\Support namespace.
 *
 * A `.type` sidecar file next to the cached body carries the real upstream
 * Content-Type instead of sniffing the bytes on read, which can misdetect an
 * unusual-but-valid image variant (and, in tests, fake non-image bytes).
 *
 * This class does NOT decide which hosts are allowed to be fetched — the
 * caller must have already decided $url is safe to request (e.g. via its own
 * allow-list). What this class DOES guard is *where the URL actually
 * resolves to*: the connection is pinned to a validated public IP
 * (SsrfGuard::resolveEntries()) and every redirect hop is re-validated the
 * same way, so a URL that looked safe cannot be used to reach an internal
 * service via DNS rebinding or a redirect.
 */
class ImageCache
{
    public function __construct(
        private readonly string $disk,
        private readonly string $pathPrefix,
        private readonly int $ttlSeconds = 86400,
    ) {}

    /**
     * The disk path a given cache key is stored under.
     */
    public function path(string $key): string
    {
        return rtrim($this->pathPrefix, '/').'/'.$key;
    }

    /**
     * Whether the disk copy exists and is not older than the configured TTL.
     */
    public function isFresh(string $key): bool
    {
        $disk = Storage::disk($this->disk);
        $path = $this->path($key);

        return $disk->exists($path)
            && $disk->lastModified($path) >= now()->subSeconds($this->ttlSeconds)->timestamp;
    }

    /**
     * Fetch and persist the image if the disk copy is missing or stale.
     * No-ops (returns true) when already fresh.
     *
     * Never throws: any failure (network error, non-2xx, non-image, blocked
     * redirect) is logged as a warning and false is returned, leaving any
     * existing stale copy in place — serving yesterday's copy beats erroring.
     *
     * @param  list<string>|null  $resolve  curl CURLOPT_RESOLVE entries (`host:port:ip`)
     *                                      pinning the connection to an already-validated IP. Pass null to have
     *                                      this method derive it via SsrfGuard::resolveEntries($url); pass an
     *                                      explicit (possibly empty) array when the caller already resolved/
     *                                      validated the URL itself.
     */
    public function warm(string $key, string $url, ?array $resolve = null): bool
    {
        if ($this->isFresh($key)) {
            return true;
        }

        if ($resolve === null) {
            $resolve = app(SsrfGuard::class)->resolveEntries($url);

            if ($resolve === null) {
                Log::warning('ImageCache refused a non-public host', ['url' => $url]);

                return false;
            }
        }

        $fetched = $this->fetch($url, $resolve);

        if ($fetched === null) {
            return false;
        }

        $disk = Storage::disk($this->disk);
        $path = $this->path($key);

        $disk->put($path, $fetched['body']);
        $disk->put($path.'.type', $fetched['type']);

        return true;
    }

    /**
     * Read back a previously warmed image, or null if the key was never
     * warmed successfully.
     *
     * @return array{body: string, type: string}|null
     */
    public function get(string $key): ?array
    {
        $disk = Storage::disk($this->disk);
        $path = $this->path($key);

        if (! $disk->exists($path) || ! $disk->exists($path.'.type')) {
            return null;
        }

        return [
            'body' => (string) $disk->get($path),
            'type' => (string) $disk->get($path.'.type'),
        ];
    }

    /**
     * A ready-to-send response for a previously warmed image, or null when
     * the key was never warmed successfully. Thin convenience wrapper around
     * get() for controllers that would otherwise hand-roll this.
     */
    public function response(string $key): ?Response
    {
        $cached = $this->get($key);

        if ($cached === null) {
            return null;
        }

        return new Response($cached['body'], 200, [
            'Content-Type' => $cached['type'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  list<string>  $resolve
     * @return array{body: string, type: string}|null
     */
    private function fetch(string $url, array $resolve): ?array
    {
        try {
            $response = Http::timeout($this->timeout())->withOptions([
                'curl' => [CURLOPT_RESOLVE => $resolve],
                // Follow redirects but re-validate every hop: the
                // CURLOPT_RESOLVE pin only covers the first host, so without
                // this a public host could redirect to an internal one and
                // defeat the IP guard.
                'allow_redirects' => [
                    'max' => $this->maxRedirects(),
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                    'on_redirect' => function ($request, $redirectResponse, $uri): void {
                        if (app(SsrfGuard::class)->resolveEntries((string) $uri) === null) {
                            throw new RuntimeException('ImageCache fetch redirect to non-public host blocked: '.$uri);
                        }
                    },
                ],
            ])->get($url);
        } catch (Throwable $e) {
            Log::warning('ImageCache fetch threw', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('ImageCache fetch failed', ['url' => $url, 'status' => $response->status()]);

            return null;
        }

        // This is typically proxied verbatim to visitors. An untrusted
        // upstream could return text/html+script; serving that from our own
        // origin would be stored XSS, so reject non-images.
        $type = strtolower(trim((string) $response->header('Content-Type')));

        if (! str_starts_with($type, 'image/')) {
            Log::warning('ImageCache rejected non-image upstream', ['url' => $url, 'type' => $type]);

            return null;
        }

        return [
            'body' => $response->body(),
            'type' => $type,
        ];
    }

    private function timeout(): int
    {
        return (int) config('image-cache.timeout', 8);
    }

    private function maxRedirects(): int
    {
        return (int) config('image-cache.max_redirects', 3);
    }
}
