<?php

use App\Catalog\ItemNotFoundException;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderRejected;
use App\Catalog\Providers\Mal\MalProvider;
use App\Catalog\TitleStatus;
use Illuminate\Support\Facades\Http;

function malProvider(): MalProvider
{
    return app(MalProvider::class);
}

test('the manga module does not exist without a MAL client id', function () {
    expect(app(MediaTypeRegistry::class)->has('manga'))->toBeFalse()
        ->and(fn () => app(ProviderRegistry::class)->get('mal'))->toThrow(InvalidArgumentException::class);
});

test('the manga type registers once the MAL client id is configured', function () {
    enableMangaModule();

    $definition = app(MediaTypeRegistry::class)->get('manga');

    expect($definition->playable)->toBeTrue()
        ->and($definition->searchable)->toBeTrue()
        ->and($definition->isRoot())->toBeTrue()
        ->and($definition->userStatus)->toBeTrue()
        ->and($definition->progressUnit?->value)->toBe('chapters')
        ->and($definition->provider)->toBe('mal');
});

test('search maps manga summaries and drops light novels and coverless hits', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga?*' => Http::response(jsonFixture('mal/search'))]);

    $results = malProvider()->search('berserk', 'en');

    // 4 hits: a light novel and a coverless entry drop out.
    expect($results)->toHaveCount(2)
        ->and($results[0]->type)->toBe('manga')
        ->and($results[0]->ref)->toBe('2')
        ->and($results[0]->title)->toBe('Berserk')
        ->and($results[0]->year)->toBe(1989)
        ->and($results[0]->posterPath)->toBe('https://cdn.myanimelist.net/images/manga/1/157897l.webp')
        // MAL means are already on the 0–10 scale.
        ->and($results[0]->communityRating)->toBe(9.5)
        ->and($results[1]->ref)->toBe('121496')
        ->and($results[1]->extra['chapters'])->toBe(201)
        ->and($results[1]->progressTotal)->toBe(201);
});

test('a search card carries the English title, the one the manga page shows', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga?*' => Http::response(jsonFixture('mal/search'))]);

    $results = malProvider()->search('solo leveling', 'pl');

    expect($results[1]->title)->toBe('Solo Leveling')
        ->and($results[1]->alternativeTitles)->toBe(['Na Honjaman Level Up', '나 혼자만 레벨업', 'Only I Level Up'])
        // The English title is also MAL's own: it is not another name.
        ->and($results[0]->title)->toBe('Berserk')
        ->and($results[0]->alternativeTitles)->toBe(['ベルセルク']);

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'alternative_titles'));
});

test('a manga MAL names in English is shown by that name, the Japanese one as its original', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/23390*' => Http::response([
        ...jsonFixture('mal/details'),
        'id' => 23390,
        'title' => 'Shingeki no Kyojin',
        'alternative_titles' => ['synonyms' => ['AoT', ' ', 'ATTACK ON TITAN!', 'Shingeki no Kyojin'], 'en' => 'Attack on Titan', 'ja' => '進撃の巨人'],
    ])]);

    $manga = malProvider()->details('manga', '23390', 'pl');

    expect($manga->summary->title)->toBe('Attack on Titan')
        ->and($manga->extra['originalTitle'])->toBe('進撃の巨人')
        ->and($manga->extra['alternativeTitles'])->toBe(['Shingeki no Kyojin', 'AoT'])
        // The shown title written another way, or a name twice, is not another name.
        ->and($manga->summary->alternativeTitles)->toBe(['Shingeki no Kyojin', '進撃の巨人', 'AoT']);
});

test('a manga without an English title keeps the name MAL gives it', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response([
        ...jsonFixture('mal/details'),
        'title' => 'Hoshi no Samidare',
        'alternative_titles' => ['synonyms' => [], 'en' => '', 'ja' => '惑星のさみだれ'],
    ])]);

    $manga = malProvider()->details('manga', '2', 'en');

    expect($manga->summary->title)->toBe('Hoshi no Samidare')
        ->and($manga->extra['originalTitle'])->toBe('惑星のさみだれ')
        ->and($manga->extra['alternativeTitles'])->toBeNull();
});

test('a very short search MAL refuses finds nothing, a longer refused one still fails', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga?*' => Http::response(['message' => 'invalid q', 'error' => 'bad_request'], 400)]);

    expect(malProvider()->search('Ao', 'en'))->toBe([])
        ->and(fn () => malProvider()->search('Ao Haru', 'en'))->toThrow(ProviderRejected::class);
});

test('manga details map MAL fields to the shared shape', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response(jsonFixture('mal/details'))]);

    $manga = malProvider()->details('manga', '2', 'en');

    expect($manga->summary->title)->toBe('Berserk')
        ->and($manga->summary->year)->toBe(1989)
        ->and($manga->summary->releaseDate)->toBe('1989-08-25')
        ->and($manga->overview)->toBe('Guts, a former mercenary now known as the Black Swordsman, is out for revenge.')
        ->and($manga->genres)->toBe(['Action', 'Adventure', 'Fantasy'])
        ->and($manga->status)->toBe(TitleStatus::Publishing)
        ->and($manga->summary->authors)->toBe(['Kentarou Miura'])
        ->and($manga->extra['chapters'])->toBe(380)
        ->and($manga->summary->progressTotal)->toBe(380)
        ->and($manga->extra['volumes'])->toBe(42)
        ->and($manga->extra['mangaFormat'])->toBe('manga')
        ->and($manga->extra['magazine'])->toBe('Young Animal')
        ->and($manga->externalLinks['mal'])->toBe('https://myanimelist.net/manga/2')
        // Manga have no runtime — chapters are the progress unit, and
        // chapter totals must never leak into time statistics via runtime.
        ->and($manga->runtime)->toBeNull();
});

test('unknown manga refs raise the not-found exception', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/*' => Http::response(['message' => 'manga not found', 'error' => 'not_found'], 404)]);

    expect(fn () => malProvider()->details('manga', '424242', 'en'))
        ->toThrow(ItemNotFoundException::class);
});

test('trending serves the popularity ranking without light novels', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/ranking*' => Http::response(jsonFixture('mal/ranking'))]);

    $trending = malProvider()->trending('en');

    // Rank 2 is a light novel — prose belongs to the books module.
    expect($trending)->toHaveCount(2)
        ->and($trending[0]->ref)->toBe('2')
        ->and($trending[1]->ref)->toBe('116778');
});
