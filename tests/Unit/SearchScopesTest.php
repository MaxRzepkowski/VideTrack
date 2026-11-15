<?php

use App\Catalog\Data\SearchScopes;

$scopes = new SearchScopes(['tmdb' => ['show', 'movie'], 'igdb' => ['game'], 'person' => ['person']]);

test('a known scope is kept and anything else falls back to the first one offered', function () use ($scopes) {
    expect($scopes->resolve('igdb'))->toBe('igdb')
        ->and($scopes->resolve(''))->toBe('tmdb')
        ->and($scopes->resolve('hologram'))->toBe('tmdb')
        ->and((new SearchScopes([]))->resolve('tmdb'))->toBeNull();
});

test('a scope lists its types in offer order', function () use ($scopes) {
    expect($scopes->keys())->toBe(['tmdb', 'igdb', 'person'])
        ->and($scopes->types('tmdb'))->toBe(['show', 'movie'])
        ->and($scopes->types('hologram'))->toBe([]);
});

test('people, collections and companies are entity scopes, a provider is not', function () {
    expect(SearchScopes::isEntity('person'))->toBeTrue()
        ->and(SearchScopes::isEntity('company'))->toBeTrue()
        ->and(SearchScopes::isEntity('tmdb'))->toBeFalse()
        ->and(SearchScopes::isEntity(null))->toBeFalse();
});
