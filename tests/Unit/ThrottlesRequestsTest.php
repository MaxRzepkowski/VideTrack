<?php

use App\Catalog\ProviderBusy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Waits are read to the millisecond: the clock stands still between the requests.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00'));
    Cache::flush();
    Sleep::fake();
});

test('a free slot is taken without waiting', function () {
    rateLimitedClient()->ask('test-limiter', 1, 60);

    Sleep::assertNeverSlept();
});

test('past the limit a request waits until the oldest one leaves the window', function () {
    $client = rateLimitedClient();
    $client->ask('test-limiter', 2, 60, 60.0);
    $this->travel(20)->seconds();
    $client->ask('test-limiter', 2, 60, 60.0);
    $this->travel(10)->seconds();

    $client->ask('test-limiter', 2, 60, 60.0);

    Sleep::assertSequence([Sleep::usleep(30_000_000)]);
});

test('the window rolls instead of starting afresh, so the limit holds across its edge', function () {
    $client = rateLimitedClient();
    $client->ask('test-limiter', 2, 60);
    $this->travel(59)->seconds();
    $client->ask('test-limiter', 2, 60);
    $this->travel(2)->seconds();

    // The first has left the window; the second still counts.
    $client->ask('test-limiter', 2, 60);
    Sleep::assertNeverSlept();

    try {
        $client->ask('test-limiter', 2, 60, 1.0);
        $this->fail('A third request within one minute went through.');
    } catch (ProviderBusy $busy) {
        expect($busy->retryAfterSeconds)->toBe(58);
    }
});

test('a wait past what the caller affords books nothing', function () {
    $client = rateLimitedClient();
    $client->ask('test-limiter', 1, 60);

    expect(fn () => $client->ask('test-limiter', 1, 60, 1.0))->toThrow(ProviderBusy::class);

    $this->travel(60)->seconds();
    $client->ask('test-limiter', 1, 60, 1.0);
    Sleep::assertNeverSlept();
});

test('every client of a service shares one count', function () {
    rateLimitedClient()->ask('test-limiter', 1, 60);

    expect(fn () => rateLimitedClient()->ask('test-limiter', 1, 60, 1.0))->toThrow(ProviderBusy::class);
    rateLimitedClient()->ask('another-limiter', 1, 60, 1.0);
});
