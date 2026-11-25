<?php

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CertificationData;
use App\Catalog\Data\CreatorLink;
use App\Catalog\Data\KeywordData;
use App\Catalog\Data\ReleaseEntry;
use App\Catalog\Department;
use App\Catalog\EpisodeBadge;
use App\Catalog\ExternalIdSource;
use App\Catalog\FilmReleaseType;
use App\Catalog\ItemMetadata;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MatureContent;
use App\Catalog\ProviderException;
use App\Catalog\Providers\Tmdb\TmdbCreators;
use App\Catalog\VideoSite;
use Illuminate\Support\Facades\Http;

test('search maps movie and tv results including posterless entries and skips people', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);

    $results = tmdbProvider()->search('matrix', 'en', MatureContent::Suggestive);

    expect($results)->toHaveCount(3)
        ->and($results[0]->type)->toBe('movie')
        ->and($results[0]->ref)->toBe('603')
        ->and($results[0]->title)->toBe('The Matrix')
        ->and($results[0]->year)->toBe(1999)
        ->and($results[1]->type)->toBe('show')
        ->and($results[1]->ref)->toBe('1399')
        ->and($results[2]->title)->toBe('No Poster Movie')
        ->and($results[2]->posterPath)->toBeNull();
});

test('movie details map shared fields and type-specific extras', function () {
    Http::fake([
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
    ]);

    $movie = tmdbProvider()->details('movie', '603', 'en');

    expect($movie->summary->title)->toBe('The Matrix')
        ->and($movie->runtime)->toBe(136)
        ->and($movie->genres)->toBe(['Action', 'Science Fiction'])
        ->and($movie->certification)->toBe('R')
        ->and($movie->extra['certificationRegion'])->toBe('US')
        ->and($movie->extra['certificationMeaning'])->toBe('Under 17 requires an accompanying parent or adult guardian.')
        ->and($movie->extra['certificationDescriptors'])->toBe(['Violence', 'Language'])
        ->and($movie->certifications)->toEqual([
            new CertificationData('US', 'R', 'Under 17 requires an accompanying parent or adult guardian.'),
            new CertificationData('PL', '16', 'Suitable for viewers aged 16 and older.'),
        ])
        ->and($movie->extra['budget'])->toBe(63000000)
        ->and($movie->extra['revenue'])->toBe(463517383)
        ->and($movie->externalLinks['tmdb'])->toBe('https://www.themoviedb.org/movie/603')
        ->and($movie->externalLinks['imdb'])->toBe('https://www.imdb.com/title/tt0133093/')
        ->and($movie->externalLinks['x'])->toBe('https://x.com/TheMatrix')
        ->and($movie->externalLinks['wikidata'])->toBe('https://www.wikidata.org/wiki/Q83495')
        ->and($movie->keywords)->toEqual([
            new KeywordData('artificial intelligence', '1'),
            new KeywordData('cyberpunk', '2'),
        ])
        // Original title matches the display title, so it is not surfaced;
        // alternative titles drop the ones already shown.
        ->and($movie->extra['originalTitle'])->toBeNull()
        ->and($movie->extra['alternativeTitles'])->toBe(['Matrix', 'La Matrice'])
        // Stored, not just linked: the IMDb export keys its rows by this id.
        ->and($movie->extra['imdbId'])->toBe('tt0133093')
        // Logos: full list for the gallery + the best one for the banner.
        ->and($movie->logos)->toBe(['/matrix-logo.png'])
        ->and($movie->extra['logoPath'])->toBe('/matrix-logo.png');
});

test('movie home-release dates take the earliest across every region', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $movie = tmdbProvider()->details('movie', '603', 'en');

    // RO (1999-08-15) beats the US digital date, and no single-region read
    // would find it — TMDB's home-release data is scattered thin across
    // countries, so the scan has to cover all of them.
    expect(ItemMetadata::fromDetails($movie)->homeReleaseDate())->toBe('1999-08-15');
});

test('the release breakdown flattens every country, oldest first', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    // Every TMDB type keeps its own name — a limited run (2) does not merge
    // with wide distribution (3), and a TV broadcast (6) is a row like any
    // other. Only the undated PL entry drops out; a note rides along untouched.
    expect(tmdbProvider()->details('movie', '603', 'en')->releases)->toEqual([
        new ReleaseEntry('US', FilmReleaseType::Limited, '1999-03-24', 'Westwood premiere'),
        new ReleaseEntry('US', FilmReleaseType::Theatrical, '1999-03-31'),
        new ReleaseEntry('PL', FilmReleaseType::Theatrical, '1999-06-11'),
        new ReleaseEntry('RO', FilmReleaseType::Digital, '1999-08-15', 'Netflix'),
        new ReleaseEntry('US', FilmReleaseType::Digital, '1999-09-21'),
        new ReleaseEntry('US', FilmReleaseType::Disc, '1999-11-02'),
        new ReleaseEntry('PL', FilmReleaseType::Tv, '2001-02-03'),
    ]);
});

test('a movie carries its spoken languages and production countries', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $movie = tmdbProvider()->details('movie', '603', 'en');

    // Countries travel as bare ISO codes — the front end names them in the UI
    // language rather than repeating TMDB's English.
    expect($movie->extra['spokenLanguages'])->toBe(['English'])
        ->and($movie->extra['productionCountries'])->toBe(['US', 'AU']);
});

test('a show carries its origin, languages, type and cinema screenings', function () {
    Http::fake(['*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')), ...providerDictionaryFakes()]);

    $show = tmdbProvider()->details('show', '1399', 'en');

    expect($show->extra['originCountry'])->toBe(['US'])
        ->and($show->extra['spokenLanguages'])->toBe(['English', 'Dothraki'])
        ->and($show->extra['showType'])->toBe('Scripted')
        // Sorted by season then episode, whatever order TMDB filed them in.
        ->and($show->extra['screenedTheatrically'])->toBe([
            ['seasonNumber' => 2, 'episodeNumber' => 5],
            ['seasonNumber' => 4, 'episodeNumber' => 1],
        ]);
});

test("the airing episode opening its season is the season's premiere, whatever its number", function (array $seasons, int $number, ?EpisodeBadge $badge) {
    Http::fake([
        '*/tv/1399*' => Http::response([
            ...jsonFixture('tmdb/tv'),
            'status' => 'Returning Series',
            'number_of_seasons' => 3,
            'seasons' => $seasons,
            'next_episode_to_air' => ['id' => 9001, 'name' => 'Next', 'air_date' => '2026-10-20', 'season_number' => 3, 'episode_number' => $number, 'episode_type' => 'standard'],
        ]),
        ...providerDictionaryFakes(),
    ]);

    $next = tmdbProvider()->details('show', '1399', 'en')->nextEpisodeToAir;

    expect($next?->episodeBadge)->toBe($badge);
})->with([
    'numbered from one' => [tmdbSeasons([0 => 2, 1 => 61, 2 => 16, 3 => 14]), 1, EpisodeBadge::SeasonPremiere],
    // A saga season numbered on from the last one: the third opens on 78.
    'numbered on from the season before' => [tmdbSeasons([0 => 2, 1 => 61, 2 => 16, 3 => 14]), 78, EpisodeBadge::SeasonPremiere],
    'further into a season numbered on' => [tmdbSeasons([0 => 2, 1 => 61, 2 => 16, 3 => 14]), 79, null],
    // The count of the seasons before falls inside this one's own numbers: not a premiere.
    'numbered from one, where the seasons before add up to its number' => [tmdbSeasons([1 => 10, 2 => 5, 3 => 22]), 16, null],
]);

/**
 * TMDB's season list of a show, from episode counts by season number.
 *
 * @param  array<int, int>  $counts
 * @return list<array<string, mixed>>
 */
function tmdbSeasons(array $counts): array
{
    return array_map(fn (int $number, int $count): array => [
        'id' => 1000 + $number,
        'season_number' => $number,
        'name' => "Season {$number}",
        'episode_count' => $count,
        'air_date' => '2020-01-01',
    ], array_keys($counts), array_values($counts));
}

test('the home-release note comes from the row that won the date', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    // Digital (1999-08-15, RO) beats disc (1999-11-02), so the calendar quotes
    // the RO row's note and not the disc one's.
    expect(tmdbProvider()->details('movie', '603', 'en')->extra['homeReleaseNote'])->toBe('Netflix');
});

test('a movie whose earliest home release carries no note reports none', function () {
    $data = jsonFixture('tmdb/movie');

    // Strip the RO region entirely: disc then wins the earliest home date.
    $data['release_dates']['results'] = array_values(array_filter(
        $data['release_dates']['results'],
        fn (array $region): bool => $region['iso_3166_1'] !== 'RO',
    ));

    Http::fake(['*/movie/603*' => Http::response($data), ...providerDictionaryFakes()]);

    $movie = tmdbProvider()->details('movie', '603', 'en');

    expect(ItemMetadata::fromDetails($movie)->homeReleaseDate())->toBe('1999-09-21')
        ->and($movie->extra['homeReleaseNote'])->toBeNull();
});

test('a movie without home-release entries reports no such dates', function () {
    $data = jsonFixture('tmdb/movie');

    foreach ($data['release_dates']['results'] as $i => $region) {
        $data['release_dates']['results'][$i]['release_dates'] = array_values(array_filter(
            $region['release_dates'],
            fn (array $entry): bool => ! in_array($entry['type'], [4, 5], true),
        ));
    }

    Http::fake(['*/movie/603*' => Http::response($data), ...providerDictionaryFakes()]);

    $movie = tmdbProvider()->details('movie', '603', 'en');

    // Null, not an empty string: the UI tells "no home release yet" apart from
    // "TMDB has no data on it", and both hang off this being absent.
    expect(ItemMetadata::fromDetails($movie)->homeReleaseDate())->toBeNull()
        ->and($movie->extra)->not->toHaveKeys(['digitalRelease', 'physicalRelease'])
        ->and($movie->certification)->toBe('R');
});

test('movie details prefer the locale certification region', function () {
    Http::fake([
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
    ]);

    $movie = tmdbProvider()->details('movie', '603', 'pl');

    expect($movie->certification)->toBe('16')
        ->and($movie->extra['certificationRegion'])->toBe('PL')
        ->and($movie->extra['certificationMeaning'])->toBe('Suitable for viewers aged 16 and older.')
        // Descriptors belong to the selected region only; the US reasons must
        // not explain a Polish age classification.
        ->and($movie->extra['certificationDescriptors'])->toBe([]);
});

test('the chip follows the viewer region, not the interface language', function () {
    Http::fake([
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
    ]);

    $movie = tmdbProvider()->details('movie', '603', 'en', 'PL');

    expect($movie->certification)->toBe('16')
        ->and($movie->extra['certificationRegion'])->toBe('PL')
        // A country with no rating of its own falls back to the US one.
        ->and(tmdbProvider()->details('movie', '603', 'pl', 'RO')->certification)->toBe('R');
});

test('a certification dictionary failure leaves the title rating intact', function () {
    Http::fake([
        '*/certification/movie/list*' => Http::response([], 500),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
    ]);

    $movie = tmdbProvider()->details('movie', '603', 'en');

    expect($movie->certification)->toBe('R')
        ->and($movie->extra['certificationRegion'])->toBe('US')
        ->and($movie->extra['certificationMeaning'])->toBeNull()
        ->and($movie->certifications)->toContainEqual(new CertificationData('US', 'R', null));
});

test('a rating missing from the dictionary has no invented meaning', function () {
    $data = jsonFixture('tmdb/movie');
    $data['release_dates']['results'][0]['release_dates'][1]['certification'] = 'UNLISTED';

    Http::fake([
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/movie/603*' => Http::response($data),
    ]);

    $movie = tmdbProvider()->details('movie', '603', 'en');

    expect($movie->certification)->toBe('UNLISTED')
        ->and($movie->extra['certificationMeaning'])->toBeNull();
});

test('a title without ratings has no certification metadata', function () {
    $data = jsonFixture('tmdb/movie');

    foreach ($data['release_dates']['results'] as $regionIndex => $region) {
        foreach ($region['release_dates'] as $dateIndex => $date) {
            $data['release_dates']['results'][$regionIndex]['release_dates'][$dateIndex]['certification'] = '';
        }
    }

    Http::fake(['*/movie/603*' => Http::response($data)]);

    $movie = tmdbProvider()->details('movie', '603', 'en');

    expect($movie->certification)->toBeNull()
        ->and($movie->extra['certificationRegion'])->toBeNull()
        ->and($movie->extra['certificationMeaning'])->toBeNull()
        ->and($movie->certifications)->toBe([]);
});

test('playable videos and postered recommendations survive mapping', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $movie = tmdbProvider()->details('movie', '603', 'en');

    // YouTube and Vimeo both have a player the section can open; the `site`
    // travels with the video so the front end knows which one to embed. A
    // recap is no kind the page offers.
    expect($movie->videos)->toHaveCount(2)
        ->and($movie->videos[0]->key)->toBe('vKQi3bBA1y8')
        ->and($movie->videos[0]->site)->toBe(VideoSite::YouTube)
        ->and($movie->videos[1]->site)->toBe(VideoSite::Vimeo)
        ->and($movie->related)->toHaveCount(1)
        ->and($movie->related[0]->title)->toBe('The Matrix Reloaded')
        // Similar: posterless entries dropped, mapped as the parent's type.
        ->and($movie->similar)->toHaveCount(1)
        ->and($movie->similar[0]->type)->toBe('movie')
        ->and($movie->similar[0]->title)->toBe('The Matrix Revolutions');
});

test('show details map seasons with specials first and hierarchical refs', function () {
    Http::fake([
        '*/certification/tv/list*' => Http::response(jsonFixture('tmdb/certifications_tv')),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
    ]);

    $show = tmdbProvider()->details('show', '1399', 'en');

    expect($show->summary->type)->toBe('show')
        ->and($show->runtime)->toBe(60)
        ->and($show->certification)->toBe('TV-MA')
        ->and($show->extra['certificationRegion'])->toBe('US')
        ->and($show->extra['certificationMeaning'])->toBe('Designed to be viewed by adults and may be unsuitable for children under 17.')
        ->and($show->certifications)->toEqual([
            new CertificationData('US', 'TV-MA', 'Designed to be viewed by adults and may be unsuitable for children under 17.'),
            new CertificationData('PL', '18', 'Suitable only for adults.'),
        ])
        ->and($show->extra['networks'][0])->toEqual(new CreatorLink('HBO', CreatorKind::Network, '49', 'tmdb', '/hbo.png'))
        ->and($show->extra['createdBy'])->toEqual([
            new CreatorLink('David Benioff', CreatorKind::Person, '9813', 'tmdb'),
            new CreatorLink('D. B. Weiss', CreatorKind::Person, '228068', 'tmdb'),
        ])
        // Full cast across all seasons (aggregate_credits), not just top billing.
        ->and($show->cast)->toHaveCount(3)
        ->and($show->cast[0]->name)->toBe('Peter Dinklage')
        ->and($show->cast[0]->role)->toBe('Tyrion Lannister')
        ->and($show->cast[0]->episodeCount)->toBe(67)
        ->and($show->cast[1]->name)->toBe('Lena Headey')
        // Several characters across seasons, ordered by episode count desc.
        ->and($show->cast[2]->roles)->toBe([
            ['role' => 'Character Two', 'episodeCount' => 4],
            ['role' => 'Character One', 'episodeCount' => 3],
        ])
        ->and($show->cast[2]->role)->toBe('Character Two')
        ->and($show->children)->toHaveCount(3)
        ->and($show->children[0]->number)->toBe(0)
        ->and($show->children[1]->ref)->toBe('1399:1')
        ->and($show->children[1]->extra['episodeCount'])->toBe(10)
        ->and($show->similar)->toHaveCount(1)
        ->and($show->similar[0]->type)->toBe('show')
        ->and($show->similar[0]->title)->toBe('Breaking Bad')
        ->and($show->logos)->toBe(['/got-logo.png'])
        ->and($show->extra['logoPath'])->toBe('/got-logo.png');
});

test('season details map episodes and carry the parent show context', function () {
    Http::fake([
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $season = tmdbProvider()->details('season', '1399:1', 'en');

    expect($season->summary->ref)->toBe('1399:1')
        ->and($season->extra['showTitle'])->toBe('Game of Thrones')
        ->and($season->extra['showRef'])->toBe('1399')
        ->and($season->children)->toHaveCount(2)
        ->and($season->children[0]->ref)->toBe('1399:1:1')
        ->and($season->children[0]->title)->toBe('Winter Is Coming')
        ->and($season->children[0]->backdropPath)->toBe('/got-s1e1.jpg')
        ->and($season->children[0]->runtime)->toBe(62)
        // Aggregate cast across the season (each person's primary role).
        ->and($season->cast)->toHaveCount(1)
        ->and($season->cast[0]->name)->toBe('Peter Dinklage')
        ->and($season->cast[0]->role)->toBe('Tyrion Lannister')
        ->and($season->cast[0]->episodeCount)->toBe(10)
        ->and($season->crew)->toHaveCount(1)
        ->and($season->crew[0]->role)->toBe('Writer')
        // Season 1 is the first non-empty season: no previous, next is season 2.
        ->and($season->extra['prevSeason'])->toBeNull()
        ->and($season->extra['nextSeason']['ref'])->toBe('1399:2')
        ->and($season->extra['nextSeason']['number'])->toBe(2);
});

test('a season without an air date borrows the year of its first episode', function () {
    $tv = jsonFixture('tmdb/tv');

    foreach ($tv['seasons'] as &$season) {
        if ($season['season_number'] === 2) {
            $season['air_date'] = null;
        }
    }
    unset($season);

    // Undated seasons are resolved in one batched append_to_response on the show
    // endpoint, not a fetch per season — so the season rides along under its
    // "season/2" key, exactly as episodeRatings consumes it.
    $tv['season/2'] = jsonFixture('tmdb/season2');

    Http::fake([
        '*/tv/1399*' => Http::response($tv),
        ...providerDictionaryFakes(),
    ]);

    $seasons = tmdbProvider()->details('show', '1399', 'en')->children;
    $season2 = collect($seasons)->firstWhere('number', 2);

    expect($season2->year)->toBe(2012)
        ->and($season2->releaseDate)->toBe('2012-04-01');
});

test('episode details map guest stars, per-episode crew and the in-season next neighbour', function () {
    Http::fake([
        '*/tv/1399/season/1/episode/1*' => Http::response(jsonFixture('tmdb/episode')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $episode = tmdbProvider()->details('episode', '1399:1:1', 'en');

    expect($episode->summary->ref)->toBe('1399:1:1')
        ->and($episode->summary->title)->toBe('Winter Is Coming')
        ->and($episode->summary->backdropPath)->toBe('/got-s1e1.jpg')
        ->and($episode->runtime)->toBe(62)
        ->and($episode->extra['showTitle'])->toBe('Game of Thrones')
        ->and($episode->summary->seasonNumber)->toBe(1)
        // Cast = the episode's own regulars (Emilia Clarke, from credits.cast —
        // NOT the show-level cast of Peter Dinklage) then the guest stars.
        ->and($episode->cast)->toHaveCount(3)
        ->and($episode->cast[0]->name)->toBe('Emilia Clarke')
        ->and($episode->cast[1]->name)->toBe('Jason Momoa')
        ->and($episode->cast[1]->role)->toBe('Khal Drogo')
        ->and($episode->crew)->toHaveCount(2)
        ->and($episode->crew[0]->role)->toBe('Director')
        ->and($episode->externalLinks['imdb'])->toBe('https://www.imdb.com/title/tt1480055/')
        // First episode of the first non-empty season: no previous, next is in-season.
        ->and($episode->summary->episodeBadge)->toBe(EpisodeBadge::SeriesPremiere)
        ->and($episode->extra['prev'])->toBeNull()
        ->and($episode->extra['next']['ref'])->toBe('1399:1:2')
        ->and($episode->extra['next']['title'])->toBe('The Kingsroad');
});

test('episode navigation crosses season boundaries at the edges', function () {
    Http::fake([
        '*/tv/1399/season/1/episode/2*' => Http::response([
            'id' => 63057,
            'episode_number' => 2,
            'season_number' => 1,
            'name' => 'The Kingsroad',
            'air_date' => '2011-04-24',
            'runtime' => 56,
            'still_path' => '/got-s1e2.jpg',
            'vote_average' => 7.9,
        ]),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $episode = tmdbProvider()->details('episode', '1399:1:2', 'en');

    // Last episode of season 1 → next is the first episode of season 2.
    expect($episode->extra['prev']['ref'])->toBe('1399:1:1')
        ->and($episode->extra['next']['ref'])->toBe('1399:2:1')
        ->and($episode->extra['next']['title'])->toBe('The North Remembers');
});

test('a malformed episode ref is rejected', function () {
    Http::fake();

    tmdbProvider()->details('episode', '1399:1', 'en');
})->throws(ItemNotFoundException::class);

test('parent refs strip the last path segment and roots have none', function () {
    expect(tmdbProvider()->parentRef('episode', '1399:2:9'))->toBe('1399:2')
        ->and(tmdbProvider()->parentRef('season', '1399:2'))->toBe('1399')
        ->and(tmdbProvider()->parentRef('show', '1399'))->toBeNull()
        ->and(tmdbProvider()->parentRef('movie', '603'))->toBeNull();
});

test('person details sort credits by popularity and retain posterless titles', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $person = tmdbProvider()->person('6384', 'en', MatureContent::Suggestive);

    expect($person->name)->toBe('Keanu Reeves')
        ->and($person->knownForDepartment)->toBe(Department::Acting)
        ->and($person->externalLinks['imdb'])->toBe('https://www.imdb.com/person/nm0000206/')
        ->and($person->credits)->toHaveCount(4)
        ->and($person->credits[0]->media->title)->toBe('John Wick')
        ->and($person->credits[0]->role)->toBe('John Wick')
        ->and($person->credits[3]->media->title)->toBe('Hidden Gem')
        ->and($person->credits[3]->media->posterPath)->toBeNull()
        ->and($person->photos)->toBe([]);
});

test('person photos are sorted by rating and capped', function () {
    $data = jsonFixture('tmdb/person');
    $data['images']['profiles'] = [
        ['file_path' => null, 'vote_average' => 10],
        ...array_map(
            fn (int $number): array => [
                'file_path' => "/profile-{$number}.jpg",
                'vote_average' => $number,
            ],
            range(1, TmdbCreators::PHOTOS_KEPT + 2),
        ),
    ];

    Http::fake(['*/person/6384*' => Http::response($data)]);

    $person = tmdbProvider()->person('6384', 'en', MatureContent::Suggestive);

    expect($person->photos)->toHaveCount(TmdbCreators::PHOTOS_KEPT)
        ->and($person->photos[0])->toBe('/profile-'.(TmdbCreators::PHOTOS_KEPT + 2).'.jpg')
        ->and($person->photos[TmdbCreators::PHOTOS_KEPT - 1])->toBe('/profile-3.jpg');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'append_to_response=combined_credits%2Cexternal_ids%2Ctranslations%2Cimages'));
});

test('network credits retain shows without posters', function () {
    Http::fake([
        '*/network/49*' => Http::response([
            'id' => 49,
            'name' => 'HBO',
            'logo_path' => '/hbo.png',
            'origin_country' => 'US',
        ]),
        '*/discover/tv*' => Http::response([
            'results' => [
                [
                    'id' => 1399,
                    'name' => 'Game of Thrones',
                    'first_air_date' => '2011-04-17',
                    'poster_path' => '/got-poster.jpg',
                ],
                [
                    'id' => 4242,
                    'name' => 'Posterless Show',
                    'first_air_date' => '2026-09-13',
                    'poster_path' => null,
                ],
            ],
        ]),
    ]);

    $network = tmdbProvider()->network('49', 'en', MatureContent::Suggestive);

    expect($network->credits)->toHaveCount(2)
        ->and($network->credits[1]->media->title)->toBe('Posterless Show')
        ->and($network->credits[1]->media->posterPath)->toBeNull();
});

test('company credits retain movies and shows without posters', function () {
    Http::fake([
        '*/company/41077*' => Http::response([
            'id' => 41077,
            'name' => 'A24',
            'logo_path' => '/a24.png',
            'origin_country' => 'US',
        ]),
        '*/discover/movie*' => Http::response([
            'results' => [[
                'id' => 5001,
                'title' => 'Posterless Movie',
                'release_date' => '2026-09-13',
                'poster_path' => null,
            ]],
        ]),
        '*/discover/tv*' => Http::response([
            'results' => [[
                'id' => 5002,
                'name' => 'Posterless Show',
                'first_air_date' => '2026-09-13',
                'poster_path' => null,
            ]],
        ]),
    ]);

    $company = tmdbProvider()->company('41077', 'en', MatureContent::Suggestive);

    expect($company->credits)->toHaveCount(2)
        ->and($company->credits[0]->media->posterPath)->toBeNull()
        ->and($company->credits[1]->media->posterPath)->toBeNull();
});

test('empty translated overview falls back to english without appended translations', function () {
    $translated = jsonFixture('tmdb/movie');
    $translated['overview'] = '';

    Http::fake([
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*language=pl-PL*' => Http::response($translated),
        '*language=en-US*' => Http::response(jsonFixture('tmdb/movie')),
    ]);

    $movie = tmdbProvider()->details('movie', '603', 'pl');

    // The fixture carries no `translations` key, as a cached entry may not:
    // the second request in English still answers, so the cache never blanks
    // the overview.
    expect($movie->overview)->toBe('Set in the 22nd century, The Matrix tells the story of a computer hacker.');
    Http::assertSentCount(3);
});

/**
 * @param  array<string, mixed>  $data
 * @param  list<array<string, mixed>>  $translations
 * @return array<string, mixed>
 */
function withTranslations(array $data, array $translations): array
{
    $data['overview'] = '';
    $data['tagline'] = '';
    $data['translations'] = ['translations' => $translations];

    return $data;
}

test('an appended translation answers the empty overview in one request', function () {
    Http::fake([
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/movie/603*' => Http::response(withTranslations(jsonFixture('tmdb/movie'), [
            ['iso_639_1' => 'en', 'data' => ['overview' => 'English overview', 'tagline' => 'English tagline']],
            ['iso_639_1' => 'pl', 'data' => ['overview' => 'Polski opis', 'tagline' => 'Polski slogan']],
        ])),
    ]);

    $movie = tmdbProvider()->details('movie', '603', 'pl');

    expect($movie->overview)->toBe('Polski opis')
        ->and($movie->tagline)->toBe('Polski slogan');

    // One details round trip plus the independently cached dictionary.
    Http::assertSentCount(2);
});

test('a missing viewer language falls through to english, then to the original', function () {
    $english = withTranslations(jsonFixture('tmdb/movie'), [
        ['iso_639_1' => 'ja', 'data' => ['overview' => '日本語']],
        ['iso_639_1' => 'en', 'data' => ['overview' => 'English overview']],
    ]);

    Http::fake(['*/movie/603*' => Http::response($english), ...providerDictionaryFakes()]);

    expect(tmdbProvider()->details('movie', '603', 'pl')->overview)->toBe('English overview');

    // Neither Polish nor English filed: the language the film was made in is
    // the last thing better than an empty page.
    $original = withTranslations(jsonFixture('tmdb/movie'), [
        ['iso_639_1' => 'ja', 'data' => ['overview' => '日本語']],
    ]);
    $original['original_language'] = 'ja';

    Http::fake(['*/movie/604*' => Http::response($original)]);

    expect(tmdbProvider()->details('movie', '604', 'pl')->overview)->toBe('日本語');
});

test('an empty translation entry is skipped rather than shown', function () {
    Http::fake(['*/movie/603*' => Http::response(withTranslations(jsonFixture('tmdb/movie'), [
        ['iso_639_1' => 'pl', 'data' => ['overview' => '']],
        ['iso_639_1' => 'en', 'data' => ['overview' => 'English overview']],
    ])), ...providerDictionaryFakes()]);

    // TMDB files a language row as soon as a title is translated, even when
    // the overview itself was never written.
    expect(tmdbProvider()->details('movie', '603', 'pl')->overview)->toBe('English overview');
});

test('responses are cached so repeated calls hit tmdb once', function () {
    Http::fake([
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
    ]);

    tmdbProvider()->details('movie', '603', 'en');
    tmdbProvider()->details('movie', '603', 'en');

    // The title and certification dictionary each hit TMDB once.
    Http::assertSentCount(2);
});

test('upstream 404 becomes ItemNotFoundException', function () {
    Http::fake(['*/movie/999999*' => Http::response([], 404)]);

    tmdbProvider()->details('movie', '999999', 'en');
})->throws(ItemNotFoundException::class);

test('an imdb id translates to the movie ref and a tvdb id to the show ref', function () {
    Http::fake([
        '*/find/tt0111161?*external_source=imdb_id*' => Http::response([
            'movie_results' => [['id' => 278]],
            'tv_results' => [],
        ]),
        '*/find/73739?*external_source=tvdb_id*' => Http::response([
            'movie_results' => [],
            'tv_results' => [['id' => 1399]],
        ]),
    ]);

    expect(tmdbProvider()->refForExternalId('movie', ExternalIdSource::Imdb, 'tt0111161'))->toBe('278')
        ->and(tmdbProvider()->refForExternalId('show', ExternalIdSource::Tvdb, '73739'))->toBe('1399');
});

test('an id TMDB does not carry answers null rather than raising', function () {
    Http::fake(['*/find/*' => Http::response(['movie_results' => [], 'tv_results' => []])]);

    // The bucket belonging to the asked-for type is the only one read: an
    // imdb id that TMDB files under a show is not a movie.
    expect(tmdbProvider()->refForExternalId('movie', ExternalIdSource::Imdb, 'tt9999999'))->toBeNull()
        ->and(tmdbProvider()->refForExternalId('movie', ExternalIdSource::Imdb, ''))->toBeNull()
        ->and(tmdbProvider()->refForExternalId('season', ExternalIdSource::Imdb, 'tt0111161'))->toBeNull()
        ->and(tmdbProvider()->refForExternalId('movie', ExternalIdSource::Steam, '123'))->toBeNull();
});

test('a 404 from find is a miss, not an outage', function () {
    Http::fake(['*/find/*' => Http::response([], 404)]);

    expect(tmdbProvider()->refForExternalId('movie', ExternalIdSource::Imdb, 'tt0111161'))->toBeNull();
});

test('a find outage raises so the caller can tell it apart from a miss', function () {
    Http::fake(['*/find/*' => Http::response([], 500)]);

    tmdbProvider()->refForExternalId('movie', ExternalIdSource::Imdb, 'tt0111161');
})->throws(ProviderException::class);

test('missing api key raises a provider exception', function () {
    config(['tracker.tmdb.api_key' => null]);
    Http::fake();

    tmdbProvider()->search('matrix', 'en', MatureContent::Suggestive);
})->throws(ProviderException::class);

test('a v4 read token goes in the bearer header, a v3 key in the query', function (string $key, bool $bearer) {
    config(['tracker.tmdb.api_key' => $key]);
    Http::fake(['*/search/multi*' => Http::response(['results' => []])]);

    tmdbProvider()->search('matrix', 'en', MatureContent::Suggestive);

    Http::assertSent(fn ($request): bool => $bearer
        ? $request->hasHeader('Authorization', "Bearer {$key}") && ! str_contains($request->url(), 'api_key=')
        : str_contains($request->url(), "api_key={$key}") && ! $request->hasHeader('Authorization'));
})->with([
    'v4 token' => ['eyJhbGciOiJIUzI1NiJ9.eyJhdWQiOiJ4In0.signature', true],
    'v3 key' => ['0123456789abcdef0123456789abcdef', false],
]);

test('a search row without an id names no title and is left out, and titles come trimmed', function () {
    Http::fake(['*/search/multi*' => Http::response(['results' => [
        ['media_type' => 'movie', 'title' => 'No Id', 'poster_path' => '/x.jpg'],
        ['media_type' => 'movie', 'id' => '', 'title' => 'Blank Id'],
        ['media_type' => 'movie', 'id' => 438631, 'title' => ' Dune ', 'release_date' => '2021-09-15'],
    ]])]);

    $results = tmdbProvider()->search('dune', 'en', MatureContent::Suggestive);

    expect($results)->toHaveCount(1)
        ->and($results[0]->ref)->toBe('438631')
        ->and($results[0]->title)->toBe('Dune');
});

test('a title answered without its id is not an answer', function () {
    $data = jsonFixture('tmdb/movie');
    unset($data['id']);
    Http::fake(['*/movie/603*' => Http::response($data), ...providerDictionaryFakes()]);

    tmdbProvider()->details('movie', '603', 'en');
})->throws(ProviderException::class, 'TMDB answered an entry without an id.');

test('every viewer-facing listing asks TMDB for adult titles only at the adult level', function (Closure $list, string $path) {
    Http::fake(['api.themoviedb.org/*' => Http::response(['results' => [], 'page' => 1, 'total_pages' => 1, 'total_results' => 0])]);

    $list(tmdbProvider(), MatureContent::Suggestive);
    $list(tmdbProvider(), MatureContent::Adult);

    $asked = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), $path))
        ->map(fn (array $pair): mixed => $pair[0]->data()['include_adult'] ?? null)
        ->unique()
        ->values()
        ->all();

    expect($asked)->toBe([false, true]);
})->with([
    'search' => [fn ($tmdb, $mature) => $tmdb->search('matrix', 'en', $mature), 'search/multi'],
    'people' => [fn ($tmdb, $mature) => $tmdb->searchPeople('keanu', 'en', $mature), 'search/person'],
    'upcoming' => [fn ($tmdb, $mature) => $tmdb->upcoming('movie', 'en', $mature), 'discover/movie'],
    'by genre' => [fn ($tmdb, $mature) => $tmdb->byGenre('movie', '28', 'en', $mature), 'discover/movie'],
    'studio' => [fn ($tmdb, $mature) => $tmdb->companyCredits('41077', 'en', $mature, 2), 'discover/movie'],
    'network' => [fn ($tmdb, $mature) => $tmdb->networkCredits('49', 'en', $mature, 2), 'discover/tv'],
]);

test('TMDB tells apart only whether adult titles come in', function () {
    expect(tmdbProvider()->matureLevels())->toBe([MatureContent::Suggestive, MatureContent::Adult]);
});
