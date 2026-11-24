<?php

use App\Catalog\Data\LocalizedTitle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => speakEnglishAndPolish());

test('one details request names the title and picks its poster in every UI language', function () {
    Http::fake(['*/movie/31407*' => Http::response(tmdbPolishFilm()), ...providerDictionaryFakes()]);

    $movie = tmdbProvider()->details('movie', '31407', 'en');

    expect($movie->localizedTitles)->toEqual([
        'en' => new LocalizedTitle('Teddy Bear', '/en-poster.jpg'),
        'pl' => new LocalizedTitle('Miś', '/pl-poster.jpg'),
    ])
        // The page reads the same choice as the stored cards.
        ->and($movie->summary->title)->toBe('Teddy Bear')
        ->and($movie->summary->posterPath)->toBe('/en-poster.jpg')
        ->and($movie->extra['originalTitle'])->toBe('Miś');

    // Every language rode along on the one request, never a request each.
    $details = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'movie/31407'));

    expect($details)->toHaveCount(1)
        ->and($details[0][0]['include_image_language'])->toBe('en,pl,null');
});

test('the gallery and the logo stay english and language-free, whatever else the request brought', function () {
    Http::fake(['*/movie/31407*' => Http::response(tmdbPolishFilm()), ...providerDictionaryFakes()]);

    $movie = tmdbProvider()->details('movie', '31407', 'pl');

    expect($movie->posters)->toBe(['/en-poster.jpg', '/textless.jpg'])
        ->and($movie->extra['logoPath'])->toBe('/en-logo.png')
        ->and($movie->summary->title)->toBe('Miś')
        ->and($movie->summary->posterPath)->toBe('/pl-poster.jpg');
});

test('a show named only in its original language reads that name; a language without a name or poster reads english', function () {
    $show = jsonFixture('tmdb/tv');
    $show['original_language'] = 'en';
    $show['original_name'] = 'Game of Thrones';
    // TMDB leaves the original language's row empty.
    $show['translations'] = ['translations' => [
        ['iso_639_1' => 'en', 'iso_3166_1' => 'US', 'data' => ['name' => '']],
    ]];
    $show['images'] = ['posters' => [['iso_639_1' => 'en', 'file_path' => '/en-poster.jpg']], 'backdrops' => [], 'logos' => []];

    Http::fake(['*/tv/1399*' => Http::response($show), ...providerDictionaryFakes()]);

    expect(tmdbProvider()->details('show', '1399', 'pl')->localizedTitles)->toEqual([
        'en' => new LocalizedTitle('Game of Thrones', '/en-poster.jpg'),
        'pl' => new LocalizedTitle('Game of Thrones', '/en-poster.jpg'),
    ]);
});

test('a film translated into neither UI language falls back to the english name, not the original script', function () {
    $film = jsonFixture('tmdb/movie');
    $film['original_language'] = 'ja';
    $film['original_title'] = '千と千尋の神隠し';
    $film['translations'] = ['translations' => [
        ['iso_639_1' => 'ja', 'iso_3166_1' => 'JP', 'data' => ['title' => '']],
        ['iso_639_1' => 'en', 'iso_3166_1' => 'US', 'data' => ['title' => 'Spirited Away']],
    ]];

    Http::fake(['*/movie/129*' => Http::response($film), ...providerDictionaryFakes()]);

    expect(tmdbProvider()->details('movie', '129', 'pl')->localizedTitles['pl']->title)->toBe('Spirited Away');
});

test('a language translated for several regions reads the variant of its UI region', function () {
    config(['tracker.supported_locales' => ['en', 'pt_BR']]);
    $film = tmdbPolishFilm();
    $film['translations'] = ['translations' => [
        ['iso_639_1' => 'en', 'iso_3166_1' => 'US', 'data' => ['title' => 'Teddy Bear']],
        ['iso_639_1' => 'pt', 'iso_3166_1' => 'PT', 'data' => ['title' => 'O Ursinho']],
        ['iso_639_1' => 'pt', 'iso_3166_1' => 'BR', 'data' => ['title' => 'O Ursinho de Pelúcia']],
    ]];

    Http::fake(['*/movie/31407*' => Http::response($film), ...providerDictionaryFakes()]);

    expect(tmdbProvider()->details('movie', '31407', 'en')->localizedTitles['pt_BR']->title)->toBe('O Ursinho de Pelúcia');
});
