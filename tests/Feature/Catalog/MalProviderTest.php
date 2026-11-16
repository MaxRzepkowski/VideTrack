<?php

use App\Catalog\Data\RankingPosition;
use App\Catalog\DatePrecision;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderRejected;
use App\Catalog\Providers\Mal\MalProvider;
use App\Catalog\RankingKind;
use App\Catalog\Relations\TitleRelation;
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

    // 6 hits: a light novel, a coverless entry and an adult one drop out.
    expect($results)->toHaveCount(3)
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

test('adult manga stay out of a search, asked of MAL together with the rest; suggestive ones stay in', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga?*' => Http::response(jsonFixture('mal/search'))]);

    $refs = array_map(fn ($manga) => $manga->ref, malProvider()->search('manga', 'en'));

    expect($refs)->not->toContain('900001')
        ->and($refs)->toContain('900002');

    Http::assertSent(fn ($request) => ($request->data()['nsfw'] ?? null) === 'true');
});

test('a manga MAL rates adult is filed as adult, one it rates suggestive is not', function (string $rating, bool $adult) {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response([...jsonFixture('mal/details'), 'nsfw' => $rating])]);

    expect(malProvider()->details('manga', '2', 'en')->adult)->toBe($adult);
})->with([
    'black' => ['black', true],
    'gray' => ['gray', false],
    'white' => ['white', false],
    'one MAL adds later' => ['red', false],
]);

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

test('a manga page names what each author did, its rankings, its background and the years it ran', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response([
        ...jsonFixture('mal/details'),
        'end_date' => '2021-05',
        'authors' => [
            ['node' => ['id' => 1, 'first_name' => 'Tsugumi', 'last_name' => 'Ohba'], 'role' => 'Story'],
            ['node' => ['id' => 2, 'first_name' => 'Takeshi', 'last_name' => 'Obata'], 'role' => 'Art'],
            ['node' => ['id' => 3, 'first_name' => 'Kentarou', 'last_name' => 'Miura'], 'role' => 'Story & Art'],
            // A part MAL has not been seen to send: the author stays an author.
            ['node' => ['id' => 4, 'first_name' => 'Kouji', 'last_name' => 'Mori'], 'role' => 'Supervision'],
        ],
    ])]);

    $manga = malProvider()->details('manga', '2', 'en');

    expect($manga->extra['authorRoles'])->toBe(['Tsugumi Ohba' => ['story'], 'Takeshi Obata' => ['art'], 'Kentarou Miura' => ['story', 'art']])
        ->and($manga->summary->authors)->toBe(['Tsugumi Ohba', 'Takeshi Obata', 'Kentarou Miura', 'Kouji Mori'])
        ->and($manga->extra['endDate'])->toBe('2021-05-01')
        ->and($manga->rankings)->toEqual([new RankingPosition(RankingKind::Score, 1), new RankingPosition(RankingKind::Popularity, 1)])
        ->and($manga->extra['background'])->toStartWith('Berserk won the Award for Excellence');
});

test('the manga readers recommend come most recommended first, under their English title, without novels', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response(jsonFixture('mal/details'))]);

    $related = malProvider()->details('manga', '2', 'en')->related;

    expect(array_map(fn ($manga) => $manga->ref, $related))->toBe(['44347', '656'])
        ->and($related[0]->title)->toBe('One-Punch Man')
        ->and($related[0]->type)->toBe('manga');

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'recommendations{id,title,main_picture,alternative_titles'));
});

test('the manga MAL ties to a manga are shown by how they stand to it, without novels or ties that say too little', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response(jsonFixture('mal/details'))]);

    $ties = malProvider()->details('manga', '2', 'en')->ties;

    // "Other" says too little and the side-story novel is prose: both drop out.
    // Which of the rest a page shows, and in what order, is TieRules' call.
    expect(array_map(fn ($tie) => [$tie->relation, $tie->media->ref], $ties))->toBe([
        [TitleRelation::SameUniverse, '92299'],
        [TitleRelation::SpinOff, '44001'],
    ]);

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'related_manga{id,title,main_picture,alternative_titles'));
});

test('a start MAL knows only to the year or the month says so, rather than a stand-in day', function (string $start, DatePrecision $precision, string $date) {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/2*' => Http::response([...jsonFixture('mal/details'), 'start_date' => $start])]);

    $summary = malProvider()->details('manga', '2', 'en')->summary;

    expect($summary->releaseDate)->toBe($date)
        ->and($summary->releasePrecision)->toBe($precision);
})->with([
    'a year' => ['1989', DatePrecision::Year, '1989-01-01'],
    'a month' => ['1989-08', DatePrecision::Month, '1989-08-01'],
    'a day' => ['1989-08-25', DatePrecision::Day, '1989-08-25'],
]);

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
