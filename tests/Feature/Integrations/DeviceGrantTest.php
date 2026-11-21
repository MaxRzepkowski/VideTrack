<?php

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\DeviceGrant;
use App\Integrations\Connections\DeviceGrantClient;
use App\Integrations\Connections\DevicePoll;
use App\Integrations\Connections\GrantUnavailable;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * The device flow every connected service shares, against a made-up
 * service at auth.example.test.
 */

beforeEach(fn () => Http::preventStrayRequests());

function exampleGrant(?string $verificationUri = null): DeviceGrant
{
    return new DeviceGrant(
        service: 'Example',
        baseUrl: 'https://auth.example.test',
        clientId: 'example-client',
        scope: 'read',
        devicePath: '/device',
        tokenPath: '/token',
        revokePath: '/revoke',
        rejectedKey: 'import.simkl_did_not_accept_this_instance',
        notStartedKey: 'import.simkl_could_not_start_the_connection',
        expiredKey: 'import.simkl_stopped_accepting_this_connection_connect',
        verificationUri: $verificationUri,
    );
}

function exampleConnection(int $expiresInSeconds = DeviceGrantClient::REFRESH_MARGIN_SECONDS * 2): ServiceConnection
{
    return deviceConnection(User::factory()->create(), 'example', [
        'access_token' => 'access-one',
        'refresh_token' => 'refresh-one',
        'expires_at' => now()->addSeconds($expiresInSeconds)->getTimestamp(),
    ]);
}

test('a code without an address to send the user to has not started the connection', function () {
    Http::fake(['auth.example.test/device' => Http::response(['device_code' => 'device-one', 'user_code' => 'ABCD-1234', 'expires_in' => 600])]);

    expect(fn () => app(DeviceGrantClient::class)->begin(exampleGrant()))
        ->toThrow(ConnectionRejected::class, 'import.simkl_could_not_start_the_connection');

    $started = app(DeviceGrantClient::class)->begin(exampleGrant('https://example.test/link'));

    expect($started->verificationUri)->toBe('https://example.test/link')
        ->and($started->expiresIn)->toBe(600)
        ->and($started->interval)->toBe(DeviceGrantClient::DEFAULT_INTERVAL_SECONDS);
});

test('a poll reads pending, slow down, a refusal and an app the service does not know', function (int $status, array $body, DevicePoll|string $outcome) {
    Http::fake(['auth.example.test/token' => Http::response($body, $status)]);

    $poll = fn () => app(DeviceGrantClient::class)->poll(exampleGrant(), 'device-one');

    is_string($outcome)
        ? expect($poll)->toThrow($outcome)
        : expect($poll())->toBe($outcome);
})->with([
    'pending' => [400, ['error' => 'authorization_pending'], DevicePoll::Pending],
    'slow down' => [400, ['error' => 'slow_down'], DevicePoll::SlowDown],
    'refused' => [400, ['error' => 'access_denied'], DevicePoll::Expired],
    'expired' => [400, ['error' => 'expired_token'], DevicePoll::Expired],
    'unknown app' => [400, ['error' => 'invalid_client'], ConnectionRejected::class],
    'down' => [503, [], GrantUnavailable::class],
]);

test('a busy service at refresh time leaves the grant alone, and the refresh is not sent again', function (int $status) {
    $connection = exampleConnection(expiresInSeconds: DeviceGrantClient::REFRESH_MARGIN_SECONDS - 3600);

    Http::fake(['auth.example.test/token' => Http::response(['error' => 'Too Many Requests'], $status)]);

    expect(fn () => app(DeviceGrantClient::class)->call(exampleGrant(), $connection, fn (string $token): Response => Http::get('https://api.example.test/me')))
        ->toThrow(GrantUnavailable::class, 'import.service_answered_token_refresh');

    expect(requestsTo('/token'))->toBe(1)
        ->and($connection->refresh()->credential('refresh_token'))->toBe('refresh-one');
})->with([503]);

test('a refused refresh means the grant is gone', function () {
    $connection = exampleConnection(expiresInSeconds: DeviceGrantClient::REFRESH_MARGIN_SECONDS - 3600);

    Http::fake(['auth.example.test/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => app(DeviceGrantClient::class)->call(exampleGrant(), $connection, fn (string $token): Response => Http::get('https://api.example.test/me')))
        ->toThrow(ConnectionExpired::class, 'import.simkl_stopped_accepting_this_connection_connect');
});

test('a rotated refresh token is kept, and the call goes with the new access token', function () {
    $connection = exampleConnection(expiresInSeconds: DeviceGrantClient::REFRESH_MARGIN_SECONDS - 3600);

    Http::fake([
        'auth.example.test/token' => Http::response(['access_token' => 'access-two', 'refresh_token' => 'refresh-two', 'expires_in' => 604800]),
        'api.example.test/*' => Http::response(['ok' => true]),
    ]);

    $response = app(DeviceGrantClient::class)->call(exampleGrant(), $connection, fn (string $token): Response => Http::withToken($token)->get('https://api.example.test/me'));

    expect($response->json('ok'))->toBeTrue()
        ->and($connection->refresh()->credential('refresh_token'))->toBe('refresh-two');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.example.test/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-one'
        && $request['client_id'] === 'example-client');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.example.test/') && $request->header('Authorization') === ['Bearer access-two']);
});

test('a refresh answer without a refresh token keeps the one just used', function () {
    $connection = exampleConnection(expiresInSeconds: DeviceGrantClient::REFRESH_MARGIN_SECONDS - 3600);

    Http::fake([
        'auth.example.test/token' => Http::response(['access_token' => 'access-two', 'expires_in' => 604800]),
        'api.example.test/*' => Http::response(['ok' => true]),
    ]);

    app(DeviceGrantClient::class)->call(exampleGrant(), $connection, fn (string $token): Response => Http::withToken($token)->get('https://api.example.test/me'));

    expect($connection->refresh()->credential('access_token'))->toBe('access-two')
        ->and($connection->credential('refresh_token'))->toBe('refresh-one');
});

test('an answer without an access token stores nothing', function () {
    $connection = exampleConnection(expiresInSeconds: DeviceGrantClient::REFRESH_MARGIN_SECONDS - 3600);

    Http::fake(['auth.example.test/token' => Http::response(['token_type' => 'Bearer'])]);

    expect(fn () => app(DeviceGrantClient::class)->call(exampleGrant(), $connection, fn (string $token): Response => Http::get('https://api.example.test/me')))
        ->toThrow(GrantUnavailable::class, 'import.service_gave_no_token');

    expect($connection->refresh()->credential('access_token'))->toBe('access-one');
});

test('a refresh another worker still holds is waited for, then given up on without a token request', function () {
    $connection = exampleConnection(expiresInSeconds: DeviceGrantClient::REFRESH_MARGIN_SECONDS - 3600);
    Http::fake();
    // The wait is counted on the clock: a faked sleep moves it.
    Sleep::fake(syncWithCarbon: true);
    $held = Cache::lock(DeviceGrantClient::refreshLockName($connection), 3600);
    $held->get();

    try {
        expect(fn () => app(DeviceGrantClient::class)->call(exampleGrant(), $connection, fn (string $token): Response => Http::get('https://api.example.test/me')))
            ->toThrow(GrantUnavailable::class, 'import.service_token_refresh_under_way');
    } finally {
        $held->release();
    }

    expect(requestsTo('/token'))->toBe(0);
});
