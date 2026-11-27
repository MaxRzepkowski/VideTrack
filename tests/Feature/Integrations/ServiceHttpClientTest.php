<?php

use App\Integrations\ServiceHttpClient;
use App\Support\HttpRetry;
use App\Support\ServiceBusy;
use App\Support\ServiceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

test('a request names the instance, asks for JSON and goes under the base address', function () {
    Http::fake(['service.test/*' => Http::response(['ok' => true])]);

    app(ServiceHttpClient::class)->request('https://service.test/api/')->get('/me');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://service.test/api/me'
        && $request->header('User-Agent') === [config()->string('tracker.http.user_agent')]
        && $request->header('Accept') === ['application/json']);
});

test('a failure that may pass is asked again with doubling waits, an answered refusal never', function () {
    $answers = [Http::response([], 503), Http::response([], 503), Http::response(['ok' => true])];
    Http::fake(['service.test/*' => function () use (&$answers) {
        return array_shift($answers);
    }]);
    $http = app(ServiceHttpClient::class);

    $answered = $http->retrying($http->request('https://service.test'), 3, 1000, HttpRetry::isTransient(...))->get('/me');

    expect($answered->successful())->toBeTrue();
    Sleep::assertSequence([Sleep::for(1000)->milliseconds(), Sleep::for(2000)->milliseconds()]);

    Http::fake(['refusing.test/*' => Http::response([], 403)]);

    expect($http->retrying($http->request('https://refusing.test'), 3, 1000, HttpRetry::isTransient(...))->get('/me')->status())->toBe(403);
    Http::assertSentCount(1);
});

test('a connection that stays dropped is the service unavailable, without the request', function () {
    Http::fake(['service.test/*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host for https://service.test/me?key=key-of-alex')]);
    $http = app(ServiceHttpClient::class);

    $reached = fn () => $http->reach('Service', ServiceUnavailable::class, fn (): Response => $http->request('https://service.test')->get('/me', ['key' => 'key-of-alex']));

    expect($reached)->toThrow(function (ServiceUnavailable $e) {
        expect($e->getMessage())->toBe('import.service_unreachable')
            ->and($e->context()['service'])->toBe('Service')
            ->and((string) $e->context()['reason'])->not->toContain('key-of-alex');
    });
});

test('a lingering 429 is a wait for as long as the service asks, or the default', function (array $headers, int $seconds) {
    Http::fake(['service.test/*' => Http::response([], 429, $headers)]);
    $http = app(ServiceHttpClient::class);

    expect(fn () => $http->unlessBusy('Service', $http->request('https://service.test')->get('/me'), 60))
        ->toThrow(fn (ServiceBusy $e) => expect($e->retryAfterSeconds)->toBe($seconds)
            ->and($e->getMessage())->toBe('import.service_asked_to_wait'));
})->with([
    'named' => [['Retry-After' => '120'], 120],
    'not named' => [[], 60],
]);

test('any other answer is handed back for the client to read', function () {
    Http::fake(['service.test/*' => Http::response([], 503)]);
    $http = app(ServiceHttpClient::class);

    expect($http->unlessBusy('Service', $http->request('https://service.test')->get('/me'), 60)->status())->toBe(503);
});
