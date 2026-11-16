<?php

use App\Accounts\Identity\IdentityProviderRegistry;
use App\Accounts\Identity\IdentityRefusal;
use App\Accounts\Identity\IdentityRefused;
use App\Accounts\Identity\Providers\GithubIdentity;
use App\Accounts\Identity\Providers\GitlabIdentity;
use App\Accounts\Identity\Providers\GoogleIdentity;
use App\Accounts\Identity\Providers\OidcIdentity;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

// The real drivers against faked identity servers: what goes out on the trip
// there, and what the way back is read into.

const OIDC_ISSUER = 'https://id.example.com/realms/home';

function configureOidc(): void
{
    config([
        'app.url' => 'https://tracker.example.com',
        'tracker.auth.providers.oidc' => [
            'issuer' => OIDC_ISSUER,
            'client_id' => 'testing-client',
            'client_secret' => 'testing-secret',
            'label' => 'Home SSO',
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $userinfo
 */
function fakeOidcServer(
    string $issuer = OIDC_ISSUER,
    array $userinfo = [],
    ?string $namedIssuer = null,
    int $discoveryStatus = 200,
    int $tokenStatus = 200,
): void {
    Http::fake([
        "{$issuer}/.well-known/openid-configuration" => Http::response([
            'issuer' => $namedIssuer ?? $issuer,
            'authorization_endpoint' => "{$issuer}/protocol/openid-connect/auth",
            'token_endpoint' => "{$issuer}/protocol/openid-connect/token",
            'userinfo_endpoint' => "{$issuer}/protocol/openid-connect/userinfo",
        ], $discoveryStatus),
        "{$issuer}/protocol/openid-connect/token" => $tokenStatus === 200
            ? Http::response(['access_token' => 'testing-access', 'token_type' => 'Bearer'])
            : Http::response(['error' => 'invalid_client'], $tokenStatus),
        "{$issuer}/protocol/openid-connect/userinfo" => Http::response([
            'sub' => 'f1d2-subject',
            'email' => 'alex@example.com',
            'email_verified' => true,
            'name' => 'Alex Example',
            'preferred_username' => 'alex',
            ...$userinfo,
        ]),
    ]);
}

/**
 * Goes to the provider and comes back with a code, as a browser would: the
 * state it was given travels back with the code.
 *
 * @return array{location: string, query: array<string, string>}
 */
function tripTo(string $provider): array
{
    $location = (string) test()->get(route('identities.redirect', $provider))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return ['location' => $location, 'query' => $query];
}

function comeBackFrom(string $provider, string $state): TestResponse
{
    return test()->get(route('identities.callback', ['provider' => $provider, 'code' => 'testing-code', 'state' => $state]));
}

test('a provider without its settings is not offered', function (array $settings) {
    config(['tracker.auth.providers.oidc' => $settings]);

    expect(app(IdentityProviderRegistry::class)->codes())->toBe([]);
})->with([
    'no issuer' => [['issuer' => null, 'client_id' => 'id', 'client_secret' => 'secret']],
    'no secret' => [['issuer' => OIDC_ISSUER, 'client_id' => 'id', 'client_secret' => '']],
]);

test('every configured provider is offered, the instance\'s own server first', function () {
    configureOidc();
    config([
        'tracker.auth.providers.github' => ['client_id' => 'id', 'client_secret' => 'secret'],
        'tracker.auth.providers.google' => ['client_id' => 'id', 'client_secret' => 'secret'],
        'tracker.auth.providers.gitlab' => ['url' => 'https://gitlab.com', 'client_id' => 'id', 'client_secret' => 'secret'],
    ]);

    expect(app(IdentityProviderRegistry::class)->codes())->toBe([OidcIdentity::CODE, GoogleIdentity::CODE, GithubIdentity::CODE, GitlabIdentity::CODE]);
});

test('an OpenID Connect server is found from its issuer and sent a state and a PKCE challenge', function () {
    configureOidc();
    fakeOidcServer();

    ['location' => $location, 'query' => $query] = tripTo(OidcIdentity::CODE);

    expect($location)->toStartWith(OIDC_ISSUER.'/protocol/openid-connect/auth?')
        ->and($query['client_id'])->toBe('testing-client')
        ->and($query['redirect_uri'])->toBe('https://tracker.example.com/auth/oidc/callback')
        ->and($query['scope'])->toBe('openid profile email')
        ->and($query['response_type'])->toBe('code')
        ->and($query['state'])->not->toBeEmpty()
        ->and($query['code_challenge'])->not->toBeEmpty()
        ->and($query['code_challenge_method'])->toBe('S256');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', config()->string('tracker.http.user_agent')));
});

test('the way back from an OpenID Connect server signs the person in from its userinfo', function () {
    configureOidc();
    fakeOidcServer();

    $state = tripTo(OidcIdentity::CODE)['query']['state'];
    comeBackFrom(OidcIdentity::CODE, $state)->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('alex@example.com')
        ->and($user->username)->toBe('alex')
        ->and($user->name)->toBe('Alex Example')
        ->and($user->identities()->sole()->only(['provider', 'subject']))->toBe(['provider' => OidcIdentity::CODE, 'subject' => 'f1d2-subject']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/token')
        && $request['code'] === 'testing-code'
        && $request['client_secret'] === 'testing-secret'
        && $request['redirect_uri'] === 'https://tracker.example.com/auth/oidc/callback'
        && is_string($request['code_verifier']) && $request['code_verifier'] !== ''
        && $request->hasHeader('User-Agent', config()->string('tracker.http.user_agent')));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/userinfo')
        && $request->hasHeader('Authorization', 'Bearer testing-access'));
});

test('a server that sends email_verified as a string is believed', function () {
    configureOidc();
    fakeOidcServer(userinfo: ['email_verified' => 'true']);

    comeBackFrom(OidcIdentity::CODE, tripTo(OidcIdentity::CODE)['query']['state']);

    expect(User::query()->where('email', 'alex@example.com')->exists())->toBeTrue();
});

test('a way back the session never sent is refused', function () {
    configureOidc();
    fakeOidcServer();
    tripTo(OidcIdentity::CODE);

    comeBackFrom(OidcIdentity::CODE, 'forged-state')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::Interrupted->reason()]);

    $this->assertGuest();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/token'));
});

test('a discovery document naming another issuer is not followed', function () {
    configureOidc();
    fakeOidcServer(namedIssuer: 'https://elsewhere.example.com');

    $this->get(route('identities.redirect', OidcIdentity::CODE))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([IdentityRefused::FIELD => trans('account.identity_server_misconfigured')]);
});

test('an issuer with a trailing slash is the same issuer', function () {
    configureOidc();
    config(['tracker.auth.providers.oidc.issuer' => OIDC_ISSUER.'/']);
    fakeOidcServer(namedIssuer: OIDC_ISSUER.'/');

    expect(tripTo(OidcIdentity::CODE)['location'])->toStartWith(OIDC_ISSUER.'/protocol/openid-connect/auth?');
});

test('an identity server that is down or refuses the code sends the person back with the reason', function (int $discovery, int $token, string $reason) {
    configureOidc();
    fakeOidcServer(discoveryStatus: $discovery, tokenStatus: $token);

    $response = $discovery === 200
        ? comeBackFrom(OidcIdentity::CODE, tripTo(OidcIdentity::CODE)['query']['state'])
        : $this->get(route('identities.redirect', OidcIdentity::CODE));

    $response->assertRedirect(route('login'))->assertSessionHasErrors([IdentityRefused::FIELD => trans($reason)]);
    $this->assertGuest();
})->with([
    'discovery down' => [503, 200, 'account.identity_server_unreachable'],
    'the code refused' => [200, 401, 'account.identity_server_refused'],
    'the token endpoint down' => [200, 502, 'account.identity_server_unreachable'],
]);

test('GitLab is read as the OpenID Connect server it is', function () {
    config([
        'tracker.auth.providers.gitlab' => ['url' => 'https://gitlab.example.com', 'client_id' => 'id', 'client_secret' => 'secret'],
    ]);
    fakeOidcServer('https://gitlab.example.com');

    comeBackFrom(GitlabIdentity::CODE, tripTo(GitlabIdentity::CODE)['query']['state']);

    expect(User::query()->sole()->identities()->sole()->provider)->toBe(GitlabIdentity::CODE);
});

test('Google says whether it verified the address', function (bool $verified, bool $signedUp) {
    config(['tracker.auth.providers.google' => ['client_id' => 'id', 'client_secret' => 'secret']]);
    Http::fake([
        'www.googleapis.com/oauth2/v4/token' => Http::response(['access_token' => 'testing-access']),
        'www.googleapis.com/oauth2/v3/userinfo*' => Http::response([
            'sub' => '1234567890',
            'email' => 'alex@example.com',
            'email_verified' => $verified,
            'name' => 'Alex Example',
        ]),
    ]);

    $trip = tripTo(GoogleIdentity::CODE);
    expect($trip['location'])->toStartWith('https://accounts.google.com/o/oauth2/auth?');

    comeBackFrom(GoogleIdentity::CODE, $trip['query']['state']);

    expect(User::query()->exists())->toBe($signedUp);
})->with([
    'verified' => [true, true],
    'not verified' => [false, false],
]);

test('GitHub gives its primary verified address and the handle for a username', function () {
    config(['tracker.auth.providers.github' => ['client_id' => 'id', 'client_secret' => 'secret']]);
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'testing-access']),
        'api.github.com/user/emails' => Http::response([
            ['email' => 'old@example.com', 'primary' => false, 'verified' => true],
            ['email' => 'alex@example.com', 'primary' => true, 'verified' => true],
        ]),
        'api.github.com/user' => Http::response([
            'id' => 583231,
            'node_id' => 'MDQ6VXNlcjU4MzIzMQ==',
            'login' => 'alex-gh',
            'name' => 'Alex Example',
            'email' => null,
            'avatar_url' => 'https://avatars.example.com/u/583231',
        ]),
    ]);

    comeBackFrom(GithubIdentity::CODE, tripTo(GithubIdentity::CODE)['query']['state']);

    $user = User::query()->sole();
    expect($user->email)->toBe('alex@example.com')
        ->and($user->username)->toBe('alex_gh')
        ->and($user->identities()->sole()->subject)->toBe('583231');
});

test('GitHub without a primary verified address makes no account', function () {
    config(['tracker.auth.providers.github' => ['client_id' => 'id', 'client_secret' => 'secret']]);
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'testing-access']),
        'api.github.com/user/emails' => Http::response([['email' => 'alex@example.com', 'primary' => true, 'verified' => false]]),
        'api.github.com/user' => Http::response(['id' => 583231, 'node_id' => 'x', 'login' => 'alex', 'avatar_url' => '', 'email' => 'alex@example.com']),
    ]);

    comeBackFrom(GithubIdentity::CODE, tripTo(GithubIdentity::CODE)['query']['state'])
        ->assertSessionHasErrors([IdentityRefused::FIELD => IdentityRefusal::EmailUnverified->reason()]);

    expect(User::query()->exists())->toBeFalse();
});
