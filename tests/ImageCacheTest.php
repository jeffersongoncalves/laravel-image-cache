<?php

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
    Storage::disk('images')->assertExists('cache/photo.type');

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
