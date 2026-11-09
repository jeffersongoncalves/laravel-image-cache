<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ImageCache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\SsrfGuard\SsrfGuard;
use League\Flysystem\FilesystemException;
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
        $cacheKey = $this->cacheKey($key).':until';

        if ((int) $this->cache()->get($cacheKey) > now()->timestamp) {
            return true;
        }

        $until = $this->lastModified($this->path($key)) + $this->ttlSeconds;

        if ($until < now()->timestamp) {
            return false;
        }

        // Remember it so the next request skips the HEAD on a remote disk.
        $this->cache()->put($cacheKey, $until, $until - now()->timestamp);

        return true;
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

        if ($this->recentlyFailed($key)) {
            return false;
        }

        if ($resolve === null) {
            $resolve = app(SsrfGuard::class)->resolveEntries($url);

            if ($resolve === null) {
                Log::warning('ImageCache refused a non-public host', ['url' => $url]);

                return false;
            }
        }

        $fetched = $this->fetch($url, $resolve);
        $disk = Storage::disk($this->disk);
        $path = $this->path($key);

        if ($fetched === null) {
            // Negative cache: a missing upstream image (e.g. a README pointing
            // at a deleted file) would otherwise be re-fetched on every request.
            $disk->put($path.'.failed', (string) now()->timestamp);

            return false;
        }

        // Content-addressed: the same image reached through different URLs
        // (e.g. a README on several branches) is stored once.
        $hash = hash('sha256', $fetched['body']);

        if (! $disk->exists($this->blobPath($hash))) {
            $disk->put($this->blobPath($hash), $fetched['body']);
        }

        $pointer = ['blob' => $hash, 'type' => $fetched['type']];
        $disk->put($path, (string) json_encode($pointer));
        $disk->delete([$path.'.type', $path.'.failed']);

        $this->cache()->put($this->cacheKey($key).':until', now()->timestamp + $this->ttlSeconds, $this->ttlSeconds);
        $this->cache()->put($this->cacheKey($key).':ptr', $pointer, $this->ttlSeconds);

        return true;
    }

    /**
     * Whether the last fetch for this key failed less than
     * `image-cache.failure_ttl` seconds ago (0 disables the negative cache).
     */
    public function recentlyFailed(string $key): bool
    {
        $ttl = (int) config('image-cache.failure_ttl', 3600);

        return $ttl > 0
            && $this->lastModified($this->path($key).'.failed') >= now()->subSeconds($ttl)->timestamp;
    }

    /**
     * A 1x1 transparent GIF, for callers that would rather not answer an
     * <img> with a 404 when an image could not be fetched.
     */
    public static function placeholder(): Response
    {
        return new Response((string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type' => 'image/gif',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Read back a previously warmed image, or null if the key was never
     * warmed successfully.
     *
     * @return array{body: string, type: string}|null
     */
    public function get(string $key): ?array
    {
        // Read straight away and treat null as a miss: an exists() first would
        // be an extra billed round trip on S3/R2. With the pointer in the
        // Laravel cache, a hit is a single disk read (the blob).
        $path = $this->path($key);
        $ptrKey = $this->cacheKey($key).':ptr';
        /** @var array{blob: string, type: string}|null $pointer */
        $pointer = $this->cache()->get($ptrKey);
        $entry = null;

        if ($pointer === null) {
            $entry = $this->read($path);

            if ($entry === null) {
                return null;
            }

            $pointer = $this->decodePointer($entry);

            if ($pointer !== null) {
                $this->cache()->put($ptrKey, $pointer, $this->ttlSeconds);
            }
        }

        if ($pointer !== null) {
            $body = $this->read($this->blobPath($pointer['blob']));

            if ($body === null) {
                // Blob gone (pruned, wiped, lifecycle rule): drop the broken
                // pointer so the next warm() re-fetches instead of no-oping.
                $this->cache()->forget($ptrKey);
                $this->cache()->forget($this->cacheKey($key).':until');
                Storage::disk($this->disk)->delete($path);

                return null;
            }

            return ['body' => $body, 'type' => $pointer['type']];
        }

        // Legacy layout (body + `.type` sidecar), kept readable until the key is re-warmed.
        $type = $this->read($path.'.type');

        return $type === null ? null : ['body' => (string) $entry, 'type' => $type];
    }

    /**
     * Delete blobs no key points at any more. Returns how many were deleted.
     *
     * Blobs younger than an hour are kept, so a warm() that has written its
     * blob but not yet its pointer is not raced. Scans every file under the
     * prefix, so schedule it (e.g. daily) rather than calling it per request.
     */
    public function prune(): int
    {
        $disk = Storage::disk($this->disk);
        $blobDir = $this->path('blobs');
        $referenced = [];

        foreach ($disk->allFiles(rtrim($this->pathPrefix, '/')) as $file) {
            // Size check first: don't download a multi-MB legacy body just to learn it isn't a pointer.
            if (! str_starts_with($file, $blobDir.'/') && ! preg_match('/\.(type|failed)$/', $file) && $disk->size($file) <= 512) {
                $pointer = $this->decodePointer((string) $disk->get($file));

                if ($pointer !== null) {
                    $referenced[$pointer['blob']] = true;
                }
            }
        }

        $cutoff = now()->subHour()->timestamp;
        $deleted = 0;

        foreach ($disk->files($blobDir) as $blob) {
            if (! isset($referenced[basename($blob)]) && $disk->lastModified($blob) < $cutoff) {
                $disk->delete($blob);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function blobPath(string $hash): string
    {
        return $this->path('blobs/'.$hash);
    }

    private function cache(): Repository
    {
        return Cache::store(config('image-cache.cache_store'));
    }

    private function cacheKey(string $key): string
    {
        return 'image-cache:'.$this->disk.':'.$this->path($key);
    }

    /**
     * One GET; null when missing, also on disks configured with `throw => true`.
     */
    private function read(string $path): ?string
    {
        try {
            return Storage::disk($this->disk)->get($path);
        } catch (FilesystemException) {
            return null;
        }
    }

    /**
     * One HEAD; 0 when missing, instead of exists() + lastModified().
     */
    private function lastModified(string $path): int
    {
        try {
            return Storage::disk($this->disk)->lastModified($path);
        } catch (FilesystemException) {
            return 0;
        }
    }

    /**
     * @return array{blob: string, type: string}|null
     */
    private function decodePointer(string $entry): ?array
    {
        // Pointers are tiny JSON; skip decoding a (legacy) image body.
        if (strlen($entry) > 512 || ! str_starts_with($entry, '{')) {
            return null;
        }

        $data = json_decode($entry, true);

        return is_array($data)
            && is_string($data['blob'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $data['blob'])
            && is_string($data['type'] ?? null)
            ? ['blob' => $data['blob'], 'type' => $data['type']]
            : null;
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
        $maxBytes = $this->maxBytes();

        try {
            $response = Http::timeout($this->timeout())->withOptions([
                'curl' => [CURLOPT_RESOLVE => $resolve],
                // Abort mid-transfer: $total is the Content-Length when sent,
                // $downloaded catches a missing or lying header.
                'progress' => function (int $total, int $downloaded) use ($maxBytes): void {
                    if ($maxBytes > 0 && max($total, $downloaded) > $maxBytes) {
                        throw new RuntimeException('ImageCache fetch exceeded max_bytes ('.$maxBytes.')');
                    }
                },
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

        // ponytail: the progress callback above only runs under the real curl
        // handler; this re-check covers Http::fake() and any other handler.
        $body = $response->body();

        if ($maxBytes > 0 && max((int) $response->header('Content-Length'), strlen($body)) > $maxBytes) {
            Log::warning('ImageCache rejected oversized upstream', ['url' => $url, 'max_bytes' => $maxBytes]);

            return null;
        }

        return [
            'body' => $body,
            'type' => $type,
        ];
    }

    private function maxBytes(): int
    {
        $default = 10 * 1024 * 1024;
        $max = (int) config('image-cache.max_bytes', $default);

        // 0 means unlimited; a negative typo must not silently mean the same.
        return $max < 0 ? $default : $max;
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
