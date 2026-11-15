<?php

use App\Catalog\ProviderException;
use App\Catalog\Providers\Concerns\ThrottlesRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

function throttledClient(): object
{
    return new class
    {
        use ThrottlesRequests;

        public function call(float $maxWaitSeconds): void
        {
            $this->throttle('test-limiter', 1, 60, 250_000, $maxWaitSeconds);
        }
    };
}

beforeEach(fn () => RateLimiter::clear('test-limiter'));

test('a free slot is taken without waiting', function () {
    Sleep::fake();

    throttledClient()->call(1.0);

    Sleep::assertNeverSlept();
});

test('a full window waits its share in attempts, then gives up as a provider failure', function () {
    Sleep::fake();
    $client = throttledClient();
    $client->call(1.0);

    expect(fn () => $client->call(1.0))->toThrow(ProviderException::class);

    // 1 s of 250 ms naps: four tries at a slot, none of them on the wall clock.
    Sleep::assertSleptTimes(4);
});
