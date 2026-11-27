<?php

use App\Catalog\KeyRefusals;
use App\Catalog\ProviderHealth;
use App\Catalog\ProviderKeyRejected;
use Tests\TestCase;

uses(TestCase::class);

function refuseKeyOf(string $provider): void
{
    try {
        app(ProviderHealth::class)->watch($provider, fn () => throw new ProviderKeyRejected('Refused.'));
    } catch (ProviderKeyRejected) {
        // The state is what the test needs.
    }
}

test('a provider that answers is never left unasked', function () {
    $refusals = app(KeyRefusals::class);

    expect($refusals->skips('tmdb'))->toBeFalse()
        ->and($refusals->lines())->toBe([]);
});

test('a refused provider is left unasked and said once, however many items it had', function () {
    refuseKeyOf('tmdb');
    $refusals = app(KeyRefusals::class);

    expect($refusals->skips('tmdb'))->toBeTrue()
        ->and($refusals->skips('tmdb'))->toBeTrue()
        ->and($refusals->skips('igdb'))->toBeFalse()
        ->and($refusals->lines())->toBe(["tmdb: 2 left unasked, it refuses the instance's key."]);
});

test('a key refused mid-run stops the asking from then on', function () {
    $refusals = app(KeyRefusals::class);

    expect($refusals->skips('tmdb'))->toBeFalse();

    refuseKeyOf('tmdb');

    expect($refusals->skips('tmdb'))->toBeTrue();
});
