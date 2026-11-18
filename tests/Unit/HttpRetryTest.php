<?php

use App\Support\HttpRetry;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

function failedWith(int $status): RequestException
{
    return new RequestException(new Response(new Psr7Response($status)));
}

test('only a dropped connection or a server error is tried again, and a 429 where the service asks to be waited out', function (?Throwable $failure, bool $transient, bool $orThrottled) {
    expect(HttpRetry::isTransient($failure))->toBe($transient)
        ->and(HttpRetry::isTransientOrThrottled($failure))->toBe($orThrottled);
})->with([
    'connection dropped' => [new ConnectionException('reset'), true, true],
    'server error' => [failedWith(503), true, true],
    'too many' => [failedWith(429), false, true],
    'refused' => [failedWith(401), false, false],
    // An unfollowed redirect reaches the check with no exception.
    'no failure' => [null, false, false],
]);
