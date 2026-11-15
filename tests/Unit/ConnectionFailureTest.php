<?php

use App\Support\ConnectionFailure;
use Illuminate\Http\Client\ConnectionException;

// cURL appends the full request URI to its message, query string included, so
// a key sent as ?api_key= would follow the message into logs and task runs.

test('the reason keeps the cURL explanation and drops the request URI', function () {
    $e = new ConnectionException('cURL error 28: Operation timed out after 15002 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.themoviedb.org/3/movie/550?api_key=SECRET&language=en-US');

    expect(ConnectionFailure::reason($e))
        ->toBe('Operation timed out after 15002 milliseconds with 0 bytes received')
        ->not->toContain('SECRET');
});

test('a message without the "see" link is trimmed at the URI as well', function () {
    $e = new ConnectionException('cURL error 6: Could not resolve host: comicvine.gamespot.com for https://comicvine.gamespot.com/api/search/?api_key=SECRET');

    expect(ConnectionFailure::reason($e))->toBe('Could not resolve host: comicvine.gamespot.com');
});

test('a message in an unknown shape is never repeated', function () {
    $e = new ConnectionException('Connection refused for https://example.test/?token=SECRET');

    expect(ConnectionFailure::reason($e))->toBe('connection failed');
});
