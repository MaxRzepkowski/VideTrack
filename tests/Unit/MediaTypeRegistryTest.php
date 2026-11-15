<?php

use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;

test('returns registered definitions', function () {
    $registry = new MediaTypeRegistry;
    $registry->register(new MediaTypeDefinition(code: 'movie', provider: 'tmdb', playable: true, searchable: true));

    expect($registry->has('movie'))->toBeTrue()
        ->and($registry->get('movie')->provider)->toBe('tmdb')
        ->and($registry->get('movie')->isRoot())->toBeTrue();
});

test('throws for unknown type', function () {
    $registry = new MediaTypeRegistry;

    expect(fn () => $registry->get('game'))->toThrow(InvalidArgumentException::class);
});

test('lists searchable type codes', function () {
    $registry = new MediaTypeRegistry;
    $registry->register(new MediaTypeDefinition(code: 'movie', provider: 'tmdb', searchable: true));
    $registry->register(new MediaTypeDefinition(code: 'season', provider: 'tmdb', parentType: 'show'));

    expect($registry->searchableCodes())->toBe(['movie']);
});

test('a type climbs to its root, and one placed under an unknown parent is its own', function () {
    $registry = new MediaTypeRegistry;
    $registry->register(new MediaTypeDefinition(code: 'show', provider: 'tmdb', childType: 'season'));
    $registry->register(new MediaTypeDefinition(code: 'season', provider: 'tmdb', parentType: 'show', childType: 'episode'));
    $registry->register(new MediaTypeDefinition(code: 'episode', provider: 'tmdb', parentType: 'season'));
    $registry->register(new MediaTypeDefinition(code: 'volume', provider: 'x', parentType: 'series'));

    $codes = fn (string $code): array => array_map(fn (MediaTypeDefinition $type): string => $type->code, $registry->lineage($code));

    expect($codes('episode'))->toBe(['episode', 'season', 'show'])
        ->and($codes('show'))->toBe(['show'])
        ->and($codes('volume'))->toBe(['volume']);
});

test('a container root is a root with children: the show, not its season, not a film', function () {
    $registry = new MediaTypeRegistry;
    $registry->register(new MediaTypeDefinition(code: 'show', provider: 'tmdb', childType: 'season'));
    $registry->register(new MediaTypeDefinition(code: 'season', provider: 'tmdb', parentType: 'show', childType: 'episode'));
    $registry->register(new MediaTypeDefinition(code: 'movie', provider: 'tmdb', playable: true));

    expect($registry->containerRootCodes())->toBe(['show']);
});

test('a provider\'s companies make the first searchable root type it serves', function () {
    $registry = new MediaTypeRegistry;
    $registry->register(new MediaTypeDefinition(code: 'movie', provider: 'tmdb', searchable: true));
    $registry->register(new MediaTypeDefinition(code: 'show', provider: 'tmdb', searchable: true, childType: 'season'));
    $registry->register(new MediaTypeDefinition(code: 'game', provider: 'igdb', searchable: true));

    expect($registry->rootServedBy('tmdb'))->toBe('movie')
        ->and($registry->rootServedBy('igdb'))->toBe('game')
        ->and($registry->rootServedBy('comicvine'))->toBeNull();
});
