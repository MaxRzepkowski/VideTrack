<?php

use App\Integrations\WebPush\VapidKeys;

/*
 * The instance's push key pair: given by the operator, or made on the first
 * start and kept, never made again over one already there.
 */

test('the first start makes a pair, readable by the instance alone, and later starts keep it', function () {
    $path = scratchDirectory('vapid').'/vapid.json';
    config(['tracker.webpush.keys_path' => $path]);

    $this->artisan('vide:webpush:keys')->expectsOutputToContain($path)->assertSuccessful();
    $made = app(VapidKeys::class)->current();
    $this->artisan('vide:webpush:keys')->doesntExpectOutputToContain($path)->assertSuccessful();

    expect($made)->not->toBeNull()
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(app(VapidKeys::class)->current()?->publicKey)->toBe($made?->publicKey);
});

test('a pair the operator gives wins, and nothing is made beside it', function () {
    $path = scratchDirectory('vapid').'/vapid.json';
    config(['tracker.webpush.keys_path' => $path, 'tracker.webpush.public_key' => 'given-public', 'tracker.webpush.private_key' => 'given-private']);

    $this->artisan('vide:webpush:keys')->assertSuccessful();

    expect(app(VapidKeys::class)->current()?->publicKey)->toBe('given-public')
        ->and(file_exists($path))->toBeFalse();
});

test('the subject a push service reaches the operator by is https or mailto, never anybody\'s address by guess', function (?string $named, string $appUrl, ?string $subject) {
    config(['tracker.webpush.public_key' => 'public', 'tracker.webpush.private_key' => 'private', 'tracker.webpush.subject' => $named, 'app.url' => $appUrl]);

    expect(app(VapidKeys::class)->current()?->subject)->toBe($subject ?? config('tracker.source_url'));
})->with([
    'named' => ['mailto:admin@example.com', 'http://videtrack.lan', 'mailto:admin@example.com'],
    'an https instance' => [null, 'https://videtrack.example.com', 'https://videtrack.example.com'],
    // Apple refuses an http: subject: the source's address stands in.
    'an http instance' => [null, 'http://videtrack.lan', null],
]);
