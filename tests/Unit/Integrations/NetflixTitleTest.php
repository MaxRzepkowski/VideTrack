<?php

use App\Integrations\Netflix\NetflixTitle;

test('an episode reads as its show, season and title, in the language of the account', function (string $value, string $show, int $season, string $episode) {
    $title = NetflixTitle::read($value);

    expect($title->isEpisode())->toBeTrue()
        ->and([$title->title, $title->season, $title->episodeTitle])->toBe([$show, $season, $episode]);
})->with([
    'English' => ['Stranger Things: Season 1: Chapter One: The Vanishing of Will Byers', 'Stranger Things', 1, 'Chapter One: The Vanishing of Will Byers'],
    'Polish' => ['Wiedźmin: Sezon 2: Ziarno prawdy', 'Wiedźmin', 2, 'Ziarno prawdy'],
    'German' => ['Dark: Staffel 3: Deja-vu', 'Dark', 3, 'Deja-vu'],
    'Spanish part' => ['La casa de papel: Parte 3: Hemos vuelto', 'La casa de papel', 3, 'Hemos vuelto'],
    'French' => ['Lupin: Partie 1: Chapitre 1', 'Lupin', 1, 'Chapitre 1'],
    'a show with a colon in its name' => ['Love, Death & Robots: Volume 3: Three Robots: Exit Strategies', 'Love, Death & Robots', 3, 'Three Robots: Exit Strategies'],
    'a series of one season' => ["The Queen's Gambit: Limited Series: Openings", "The Queen's Gambit", 1, 'Openings'],
    'a Polish series of one season' => ['Sprawa Moniki: Miniserial: Odcinek 1', 'Sprawa Moniki', 1, 'Odcinek 1'],
]);

test('an episode titled by its number alone reads its number', function () {
    expect(NetflixTitle::read('Sprawa Moniki: Miniserial: Odcinek 4')->episode)->toBe(4)
        ->and(NetflixTitle::read('Dark: Staffel 3: Deja-vu')->episode)->toBeNull();
});

test('a film is its name, a season word with nothing after it included', function (string $value) {
    $title = NetflixTitle::read($value);

    expect($title->isEpisode())->toBeFalse()
        ->and($title->title)->toBe($value);
})->with([
    'plain' => ['Roma'],
    'with a colon' => ['Glass Onion: A Knives Out Mystery'],
    'a volume of its own' => ['Kill Bill: Volume 1'],
    'a part in words' => ['Dune: Part Two'],
]);
