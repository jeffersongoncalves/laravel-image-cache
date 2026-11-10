<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\ImageCache\ImageCache;

function makeImageCache(int $ttl = 86400): ImageCache
{
    return new ImageCache('images', 'cache', $ttl);
}

beforeEach(function () {
    Storage::fake('images');
});

it('fetches and persists body and type on a successful warm', function () {
    Http::fake([
        'https://1.1.1.1/photo.png' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png']),
    ]);

    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeTrue();

    Storage::disk('images')->assertExists('cache/photo');
    Storage::disk('images')->assertExists('cache/blobs/'.hash('sha256', 'binary-image-data'));

    expect($cache->get('photo'))->toBe([
        'body' => 'binary-image-data',
        'type' => 'image/png',
    ]);
});

it('no-ops and does not re-fetch when the disk copy is already fresh', function () {
    Storage::disk('images')->put('cache/photo', 'old-data');
    Storage::disk('images')->put('cache/photo.type', 'image/jpeg');

    Http::fake();

    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeTrue();

    Http::assertNothingSent();
    expect($cache->get('photo')['body'])->toBe('old-data');
});

it('rejects a non-image content type and does not write to disk', function () {
    Http::fake([
        'https://1.1.1.1/*' => Http::response('<script>alert(1)</script>', 200, ['Content-Type' => 'text/html']),
    ]);

    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeFalse();

    Storage::disk('images')->assertMissing('cache/photo');
    expect($cache->get('photo'))->toBeNull();
});

it('leaves an existing stale copy untouched when the fetch fails', function () {
    Storage::disk('images')->put('cache/photo', 'stale-data');
    Storage::disk('images')->put('cache/photo.type', 'image/png');
    touch(Storage::disk('images')->path('cache/photo'), time() - 90000);

    Http::fake([
        'https://1.1.1.1/*' => Http::response('', 500),
    ]);

    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeFalse();

    expect($cache->get('photo'))->toBe([
        'body' => 'stale-data',
        'type' => 'image/png',
    ]);
});

it('blocks a redirect hop that points at a non-public host and writes nothing', function () {
    Http::fake([
        'https://1.1.1.1/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeFalse();

    Storage::disk('images')->assertMissing('cache/photo');
});

it('refuses a url resolving to a non-public host without making any request', function () {
    Http::fake();

    $cache = makeImageCache();

    expect($cache->warm('photo', 'http://127.0.0.1/photo.png'))->toBeFalse();

    Http::assertNothingSent();
});

it('returns null from get() for a never-warmed key', function () {
    $cache = makeImageCache();

    expect($cache->get('missing'))->toBeNull();
});

it('accepts a pre-resolved curl resolve pin without re-deriving it', function () {
    Http::fake([
        'https://1.1.1.1/*' => Http::response('bytes', 200, ['Content-Type' => 'image/gif']),
    ]);

    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png', ['1.1.1.1:443:1.1.1.1']))->toBeTrue();

    expect($cache->get('photo')['type'])->toBe('image/gif');
});

it('builds the disk path from the configured prefix', function () {
    expect(makeImageCache()->path('abc'))->toBe('cache/abc');
});

it('reports freshness based on the configured ttl', function () {
    Storage::disk('images')->put('cache/photo', 'data');

    $cache = makeImageCache(60);

    expect($cache->isFresh('photo'))->toBeTrue();

    touch(Storage::disk('images')->path('cache/photo'), time() - 120);
    Cache::flush(); // isFresh() remembered "fresh until"; we aged the file behind its back

    expect($cache->isFresh('photo'))->toBeFalse();
});

it('returns a ready-to-send response for a warmed key', function () {
    Http::fake([
        'https://1.1.1.1/photo.png' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png']),
    ]);

    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');

    $response = $cache->response('photo');

    expect($response)->not->toBeNull()
        ->and($response->getContent())->toBe('binary-image-data')
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('returns null response for a never-warmed key', function () {
    expect(makeImageCache()->response('missing'))->toBeNull();
});

it('skips the upstream after a transient failure until the failure ttl passes', function () {
    Http::fake([
        'https://1.1.1.1/*' => Http::sequence()
            ->push('', 500)
            ->push('binary-image-data', 200, ['Content-Type' => 'image/png']),
    ]);

    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeFalse()
        ->and($cache->recentlyFailed('photo'))->toBeTrue()
        ->and($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeFalse();

    Http::assertSentCount(1);

    $this->travel(3601)->seconds();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeTrue();

    Storage::disk('images')->assertMissing('cache/photo.failed');
});

it('treats a timeout as transient', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');

    $this->travel(3599)->seconds();
    expect($cache->recentlyFailed('photo'))->toBeTrue();

    $this->travel(2)->seconds();
    expect($cache->recentlyFailed('photo'))->toBeFalse();
});

it('negative-caches a deterministic failure for the permanent failure ttl', function (int $status, string $type) {
    Http::fake(['https://1.1.1.1/*' => Http::response('nope', $status, ['Content-Type' => $type])]);

    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');

    $this->travel(7 * 86400 - 1)->seconds();
    expect($cache->recentlyFailed('photo'))->toBeTrue()
        ->and($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeFalse();
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    expect($cache->recentlyFailed('photo'))->toBeFalse();
})->with([
    '404' => [404, 'text/html'],
    '410' => [410, 'text/html'],
    'non-image' => [200, 'text/plain'],
]);

it('honours a longer Retry-After on a 429, in seconds or as an HTTP date', function (string $retryAfter) {
    Http::fake(['https://1.1.1.1/*' => Http::response('', 429, ['Retry-After' => $retryAfter])]);

    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');

    $this->travel(7199)->seconds();
    expect($cache->recentlyFailed('photo'))->toBeTrue();

    $this->travel(2)->seconds();
    expect($cache->recentlyFailed('photo'))->toBeFalse();
})->with([
    'seconds' => ['7200'],
    'http date' => [fn () => now()->addSeconds(7200)->toRfc7231String()],
]);

it('caps a hostile Retry-After at the permanent failure ttl', function () {
    Http::fake(['https://1.1.1.1/*' => Http::response('', 429, ['Retry-After' => '999999999'])]);

    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');

    $this->travel(7 * 86400 + 1)->seconds();
    expect($cache->recentlyFailed('photo'))->toBeFalse();
});

it('keeps honouring a marker written before failures were classified', function () {
    Storage::disk('images')->put('cache/photo.failed', (string) now()->subMinutes(30)->timestamp);

    $cache = makeImageCache();

    expect($cache->recentlyFailed('photo'))->toBeTrue();

    $this->travel(31)->minutes();
    expect($cache->recentlyFailed('photo'))->toBeFalse();
});

it('retries every time when the failure ttl is 0', function () {
    config()->set('image-cache.failure_ttl', 0);
    Http::fake(['https://1.1.1.1/*' => Http::response('', 500)]);

    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');
    $cache->warm('photo', 'https://1.1.1.1/photo.png');

    Http::assertSentCount(2);
});

it('stores identical images reached through different urls once', function () {
    Http::fake(['https://1.1.1.1/*' => Http::response('same-bytes', 200, ['Content-Type' => 'image/png'])]);

    $cache = makeImageCache();
    $cache->warm('a', 'https://1.1.1.1/1.x/banner.png');
    $cache->warm('b', 'https://1.1.1.1/2.x/banner.png');

    expect(Storage::disk('images')->files('cache/blobs'))->toBe(['cache/blobs/'.hash('sha256', 'same-bytes')])
        ->and($cache->get('a'))->toBe(['body' => 'same-bytes', 'type' => 'image/png'])
        ->and($cache->get('b'))->toBe(['body' => 'same-bytes', 'type' => 'image/png']);
});

it('still reads the legacy body + .type layout and replaces it on re-warm', function () {
    $disk = Storage::disk('images');
    $disk->put('cache/photo', 'legacy-bytes');
    $disk->put('cache/photo.type', 'image/jpeg');

    $cache = makeImageCache();

    expect($cache->get('photo'))->toBe(['body' => 'legacy-bytes', 'type' => 'image/jpeg']);

    touch($disk->path('cache/photo'), time() - 90000);
    Http::fake(['https://1.1.1.1/*' => Http::response('new-bytes', 200, ['Content-Type' => 'image/png'])]);

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeTrue()
        ->and($cache->get('photo'))->toBe(['body' => 'new-bytes', 'type' => 'image/png']);

    $disk->assertMissing('cache/photo.type');
});

it('prunes only old blobs no key points at', function () {
    Http::fake(['https://1.1.1.1/*' => Http::sequence()
        ->push('old-bytes', 200, ['Content-Type' => 'image/png'])
        ->push('new-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $disk = Storage::disk('images');
    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');
    touch($disk->path('cache/photo'), time() - 90000);
    Cache::flush();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');

    $old = 'cache/blobs/'.hash('sha256', 'old-bytes');
    $new = 'cache/blobs/'.hash('sha256', 'new-bytes');

    expect($cache->prune())->toBe(0); // orphan still inside the grace period

    touch($disk->path($old), time() - 7200);
    touch($disk->path($new), time() - 7200);

    expect($cache->prune())->toBe(1);
    $disk->assertMissing($old);
    $disk->assertExists($new);
    expect($cache->get('photo')['body'])->toBe('new-bytes');
});

/**
 * Swap the 'images' disk for one that records every read-side call.
 *
 * @return ArrayObject<int, string>
 */
function spyOnImagesDisk(): ArrayObject
{
    $calls = new ArrayObject;
    $fake = Storage::disk('images');

    Storage::set('images', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $calls) extends FilesystemAdapter
    {
        public function __construct($driver, $adapter, $config, private ArrayObject $calls)
        {
            parent::__construct($driver, $adapter, $config);
        }

        public function exists($path): bool
        {
            $this->calls[] = 'exists '.$path;

            return parent::exists($path);
        }

        public function get($path): ?string
        {
            $this->calls[] = 'get '.$path;

            return parent::get($path);
        }

        public function lastModified($path): int
        {
            $this->calls[] = 'lastModified '.$path;

            return parent::lastModified($path);
        }

        public function put($path, $contents, $options = []): string|bool
        {
            $this->calls[] = 'put '.$path;

            return parent::put($path, $contents, $options);
        }

        public function delete($paths): bool
        {
            $this->calls[] = 'delete '.implode(',', (array) $paths);

            return parent::delete($paths);
        }
    });

    return $calls;
}

it('serves a fresh hit with a single disk read', function () {
    Http::fake(['https://1.1.1.1/*' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png'])]);

    makeImageCache()->warm('photo', 'https://1.1.1.1/photo.png');

    $calls = spyOnImagesDisk();
    $cache = makeImageCache(); // a later request

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeTrue()
        ->and($cache->response('photo')->getContent())->toBe('binary-image-data')
        ->and($calls->getArrayCopy())->toBe(['get cache/blobs/'.hash('sha256', 'binary-image-data')]);
});

it('exposes a warmed key\'s blob path from the Laravel cache, else one pointer read', function () {
    Http::fake(['https://1.1.1.1/*' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png'])]);

    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');
    $blob = 'cache/blobs/'.hash('sha256', 'binary-image-data');
    $calls = spyOnImagesDisk();

    expect($cache->blobPath('photo'))->toBe($blob)
        ->and($calls->getArrayCopy())->toBe([]);

    Cache::flush();

    expect($cache->blobPath('photo'))->toBe($blob)
        ->and($cache->blobPath('photo'))->toBe($blob)
        ->and($calls->getArrayCopy())->toBe(['get cache/photo']);
});

it('has no blob path for a never-warmed or legacy key', function () {
    Storage::disk('images')->put('cache/legacy', 'legacy-bytes');
    Storage::disk('images')->put('cache/legacy.type', 'image/png');

    $cache = makeImageCache();

    expect($cache->blobPath('missing'))->toBeNull()
        ->and($cache->blobPath('legacy'))->toBeNull()
        ->and($cache->url('missing'))->toBeNull();
});

it('builds a public url only when the disk has one configured', function () {
    Http::fake(['https://1.1.1.1/*' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png'])]);

    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');

    expect($cache->url('photo'))->toBeNull();

    Storage::fake('images', ['url' => 'https://assets.example.com']);
    $cache->warm('photo', 'https://1.1.1.1/photo.png');

    expect($cache->url('photo'))->toBe('https://assets.example.com/cache/blobs/'.hash('sha256', 'binary-image-data'));
});

it('serves the just-warmed bytes only once from memory', function () {
    Http::fake(['https://1.1.1.1/*' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png'])]);

    $cache = makeImageCache();
    $cache->warm('photo', 'https://1.1.1.1/photo.png');
    $calls = spyOnImagesDisk();

    $cache->get('photo');
    expect($calls->getArrayCopy())->toBe([]);

    $cache->get('photo');
    expect($calls->getArrayCopy())->toBe(['get cache/blobs/'.hash('sha256', 'binary-image-data')]);
});

it('serves a miss with a HEAD, a marker read and two PUTs, and no read back', function () {
    Http::fake(['https://1.1.1.1/*' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png'])]);

    $calls = spyOnImagesDisk();
    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeTrue()
        ->and($cache->response('photo')->getContent())->toBe('binary-image-data')
        ->and($calls->getArrayCopy())->toBe([
            'lastModified cache/photo',
            'get cache/photo.failed',
            'put cache/blobs/'.hash('sha256', 'binary-image-data'),
            'put cache/photo',
        ]);
});

it('still deletes an old .failed marker on a successful miss', function () {
    Storage::disk('images')->put('cache/photo.failed', '0');
    touch(Storage::disk('images')->path('cache/photo.failed'), time() - 7200);
    Http::fake(['https://1.1.1.1/*' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png'])]);

    $calls = spyOnImagesDisk();

    expect(makeImageCache()->warm('photo', 'https://1.1.1.1/photo.png'))->toBeTrue()
        ->and($calls->getArrayCopy())->toContain('delete cache/photo.failed');

    Storage::disk('images')->assertMissing('cache/photo.failed');
});

it('drops a pointer whose blob is gone so the next warm re-fetches', function () {
    Http::fake(['https://1.1.1.1/*' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png'])]);

    makeImageCache()->warm('photo', 'https://1.1.1.1/photo.png');
    Storage::disk('images')->delete('cache/blobs/'.hash('sha256', 'binary-image-data'));

    $cache = makeImageCache(); // a later request

    expect($cache->get('photo'))->toBeNull()
        ->and($cache->isFresh('photo'))->toBeFalse()
        ->and($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeTrue()
        ->and($cache->get('photo')['body'])->toBe('binary-image-data');
});

it('rejects an image whose Content-Length is above max_bytes', function () {
    config()->set('image-cache.max_bytes', 10);
    Http::fake(['https://1.1.1.1/*' => Http::response('small', 200, ['Content-Type' => 'image/png', 'Content-Length' => '999'])]);

    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeFalse();

    Storage::disk('images')->assertMissing('cache/photo');
    Storage::disk('images')->assertExists('cache/photo.failed');
});

it('rejects a body above max_bytes without a Content-Length', function () {
    config()->set('image-cache.max_bytes', 10);
    Http::fake(['https://1.1.1.1/*' => Http::response(str_repeat('x', 11), 200, ['Content-Type' => 'image/png'])]);

    $cache = makeImageCache();

    expect($cache->warm('photo', 'https://1.1.1.1/photo.png'))->toBeFalse();

    Storage::disk('images')->assertMissing('cache/photo');
});

it('stores a body under max_bytes, and any size when max_bytes is 0', function () {
    // One fake with distinct URLs: Laravel keeps every Http::fake() stub and
    // the first match wins, so a second fake for the same pattern is ignored.
    Http::fake([
        'https://1.1.1.1/small.png' => Http::response('0123456789', 200, ['Content-Type' => 'image/png']),
        'https://1.1.1.1/big.png' => Http::response(str_repeat('x', 100), 200, ['Content-Type' => 'image/png']),
    ]);

    $cache = makeImageCache();

    config()->set('image-cache.max_bytes', 10);
    expect($cache->warm('small', 'https://1.1.1.1/small.png'))->toBeTrue()
        ->and($cache->get('small')['body'])->toBe('0123456789');

    config()->set('image-cache.max_bytes', 0);
    expect($cache->warm('big', 'https://1.1.1.1/big.png'))->toBeTrue()
        ->and($cache->get('big')['body'])->toBe(str_repeat('x', 100));
});

it('falls back to the default limit for a negative max_bytes', function () {
    config()->set('image-cache.max_bytes', -1);
    Http::fake([
        'https://1.1.1.1/*' => Http::response('small', 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) (11 * 1024 * 1024)]),
    ]);

    expect(makeImageCache()->warm('photo', 'https://1.1.1.1/photo.png'))->toBeFalse();

    Storage::disk('images')->assertMissing('cache/photo');
});

it('builds a transparent gif placeholder response', function () {
    $response = ImageCache::placeholder();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('image/gif')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(str_starts_with((string) $response->getContent(), 'GIF89a'))->toBeTrue();
});
