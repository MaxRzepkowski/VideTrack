<?php

use App\Catalog\MediaTypeRegistry;

// Discord embeds read a type's colour from the registry, the pages from the
// CSS token; the two are one palette and must not drift apart.
test('every type colour in the registry is its token in the stylesheet', function () {
    enableGamesModule();
    enableBooksModule();
    enableMangaModule();
    enableComicsModule();

    preg_match_all('/--color-media-([a-z]+):\s*(#[0-9a-f]{6});/i', (string) file_get_contents(resource_path('css/app.css')), $matches);
    $tokens = array_change_key_case(array_combine($matches[1], array_map(strtolower(...), $matches[2])));

    $colours = [];

    foreach (app(MediaTypeRegistry::class)->all() as $definition) {
        if ($definition->color !== null) {
            // The TV hierarchy shares one token, named for the medium rather than the root type.
            $colours[$definition->code] = [strtolower($definition->color), $tokens[$definition->code === 'show' ? 'tv' : $definition->code] ?? null];
        }
    }

    expect($colours)->not->toBeEmpty();

    foreach ($colours as $code => [$registry, $token]) {
        expect($registry)->toBe($token, "the {$code} colour");
    }
});
