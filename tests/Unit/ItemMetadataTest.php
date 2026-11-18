<?php

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CertificationData;
use App\Catalog\Data\KeywordData;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\PlatformRelease;
use App\Catalog\Data\ReleaseEntry;
use App\Catalog\DatePrecision;
use App\Catalog\EpisodeType;
use App\Catalog\FilmReleaseType;
use App\Catalog\ItemMetadata;
use App\Catalog\ProgressUnit;
use App\Catalog\ReleaseRegion;
use App\Catalog\TitleStatus;

function detailsWithEverything(): MediaDetails
{
    return new MediaDetails(
        summary: new MediaSummary(type: 'movie', ref: '603', provider: 'tmdb', title: 'The Matrix', extra: ['originalTitle' => 'The Matrix']),
        tagline: 'Welcome to the Real World.',
        genres: ['Action', 'Science Fiction'],
        status: TitleStatus::Released,
        originalLanguage: 'en',
        certification: 'R',
        certifications: [new CertificationData('US', 'R', 'Restricted'), new CertificationData('DE', '16')],
        releases: [new ReleaseEntry('US', FilmReleaseType::Theatrical, '1999-03-31'), new ReleaseEntry('US', FilmReleaseType::Digital, '1999-09-21', 'iTunes')],
        keywords: [new KeywordData('simulation', '310'), new KeywordData('hacker')],
        externalLinks: ['imdb' => 'https://www.imdb.com/title/tt0133093'],
        extra: ['budget' => 63000000, 'digitalRelease' => '1999-09-21'],
    );
}

test('what a provider said reads back the same after a trip through the column', function () {
    $details = detailsWithEverything();

    $read = ItemMetadata::fromStored(ItemMetadata::fromDetails($details)->toStored());

    expect($read->tagline)->toBe($details->tagline)
        ->and($read->genres)->toBe($details->genres)
        ->and($read->originalLanguage)->toBe($details->originalLanguage)
        ->and($read->certification)->toBe($details->certification)
        ->and($read->certifications)->toEqual($details->certifications)
        ->and($read->releases)->toEqual($details->releases)
        ->and($read->keywords)->toEqual($details->keywords)
        ->and($read->externalLinks)->toBe($details->externalLinks)
        // The open fields keep both bags, summary first.
        ->and($read->extra)->toBe(['originalTitle' => 'The Matrix', 'budget' => 63000000, 'digitalRelease' => '1999-09-21']);
});

test('the column keeps one key order, so an unchanged title is not rewritten', function () {
    $stored = ItemMetadata::fromDetails(detailsWithEverything())->toStored();

    expect(array_keys($stored ?? []))->toBe([
        'originalTitle', 'budget', 'digitalRelease',
        'genres',
        'tagline', 'originalLanguage', 'certification', 'keywords', 'certifications', 'releases', 'externalLinks',
    ]);
});

test('empty fields store no key, and nothing at all stores null', function () {
    $details = new MediaDetails(summary: new MediaSummary(type: 'movie', ref: '1', provider: 'tmdb', title: 'Bare'));

    expect(ItemMetadata::fromDetails($details)->toStored())->toBeNull()
        ->and(ItemMetadata::fromSummary(new MediaSummary(type: 'episode', ref: '1:1:1', provider: 'tmdb', title: 'Pilot', runtime: 42))->toStored())
        ->toBeNull();
});

test('a stored entry that no longer makes sense is skipped, not fatal', function () {
    $read = ItemMetadata::fromStored([
        'releases' => [
            ['region' => 'US', 'kind' => 'theatrical', 'date' => '1999-03-31'],
            ['date' => '2020-01-01', 'platform' => 'PC', 'status' => null, 'region' => null],
            'garbage',
        ],
        'keywords' => [['ref' => '1'], ['name' => 'hacker']],
        'genres' => ['Action', 7],
        'externalLinks' => ['imdb' => 'https://imdb.com', 3 => 'x'],
    ]);

    expect($read->releases)->toEqual([new ReleaseEntry('US', FilmReleaseType::Theatrical, '1999-03-31')])
        ->and($read->keywords)->toEqual([new KeywordData('hacker')])
        ->and($read->genres)->toBe(['Action'])
        ->and($read->externalLinks)->toBe(['imdb' => 'https://imdb.com'])
        ->and($read->extra)->toBe([]);
});

test('a reading counter runs to the total stored under its unit', function () {
    $metadata = ItemMetadata::fromStored(['pages' => 412, 'chapters' => '0']);

    expect($metadata->progressTotal(ProgressUnit::Pages))->toBe(412)
        ->and($metadata->progressTotal(ProgressUnit::Chapters))->toBeNull()
        ->and($metadata->progressTotal(ProgressUnit::Minutes))->toBeNull();
});

test('a home release note and a cover colour read back when there is one', function () {
    $stored = ItemMetadata::fromStored(['homeReleaseNote' => 'Netflix', 'coverColor' => '#1a2b3c']);
    $bare = ItemMetadata::fromStored(['homeReleaseNote' => '  ', 'coverColor' => null]);

    expect($stored->homeReleaseNote())->toBe('Netflix')
        ->and($stored->coverColor())->toBe('#1a2b3c')
        ->and($bare->homeReleaseNote())->toBeNull()
        ->and($bare->coverColor())->toBeNull();
});

test("a book's ISBN, Goodreads id and own-language title read back when there is one", function () {
    $stored = ItemMetadata::fromStored(['isbn' => '9780618260300', 'goodreadsId' => '5907', 'originalTitle' => 'The Hobbit']);
    $bare = ItemMetadata::fromStored(['isbn' => 9780618260300, 'goodreadsId' => '']);

    expect($stored->isbn())->toBe('9780618260300')
        ->and($stored->goodreadsId())->toBe('5907')
        ->and($stored->originalTitle())->toBe('The Hobbit')
        ->and($bare->isbn())->toBeNull()
        ->and($bare->goodreadsId())->toBeNull()
        ->and($bare->originalTitle())->toBeNull();
});

test('an archived title keeps only the releases that already happened', function () {
    $metadata = new ItemMetadata(
        releases: [new ReleaseEntry('US', FilmReleaseType::Theatrical, '2026-01-01'), new ReleaseEntry('US', FilmReleaseType::Digital, '2026-12-01')],
        platformReleases: [new PlatformRelease('2026-10-10', 'PC'), new PlatformRelease('2027-03-01', 'Switch 2')],
        extra: ['budget' => 1],
    );

    $released = $metadata->releasedBy('2026-10-10');

    expect($released->releases)->toEqual([new ReleaseEntry('US', FilmReleaseType::Theatrical, '2026-01-01')])
        ->and($released->platformReleases)->toEqual([new PlatformRelease('2026-10-10', 'PC')])
        ->and($released->extra)->toBe(['budget' => 1]);
});

test('a platform release keeps how much of its date is known, one stored without it reads as an exact day', function () {
    $stored = ItemMetadata::fromStored([
        'platformReleases' => [
            ['date' => '2028-12-31', 'platform' => 'PC', 'status' => null, 'region' => 'worldwide', 'precision' => 'year'],
            ['date' => '2026-10-10', 'platform' => 'PS5', 'status' => null, 'region' => 'worldwide'],
        ],
    ]);

    expect($stored->platformReleases)->toEqual([
        new PlatformRelease('2028-12-31', 'PC', null, ReleaseRegion::Worldwide, DatePrecision::Year),
        new PlatformRelease('2026-10-10', 'PS5', null, ReleaseRegion::Worldwide),
    ])
        ->and(ItemMetadata::fromStored($stored->toStored())->platformReleases)->toEqual($stored->platformReleases);
});

test("a module's key survives a fresh read from the provider and stays out of the open fields", function () {
    $stored = ItemMetadata::fromStored(ItemMetadata::fromDetails(detailsWithEverything())->withModule('tvmazeId', 82)->toStored());
    $fresh = ItemMetadata::fromDetails(detailsWithEverything())->keepingModulesOf($stored);

    expect($fresh->module('tvmazeId'))->toBe(82)
        ->and($fresh->module('unknown'))->toBeNull()
        ->and($fresh->extra)->not->toHaveKey('modules')
        ->and($fresh->toStored()['modules'] ?? null)->toBe(['tvmazeId' => 82]);
});

test('a module key the fresh read sets wins over the remembered one', function () {
    $stored = ItemMetadata::fromDetails(detailsWithEverything())->withModule('tvmazeId', 82);

    expect(ItemMetadata::fromDetails(detailsWithEverything())->withModule('tvmazeId', 99)->keepingModulesOf($stored)->module('tvmazeId'))->toBe(99);
});

test('an episode keeps what the provider marks it as, and an unknown mark reads as none', function () {
    $stored = ItemMetadata::fromSummary(new MediaSummary(type: 'episode', ref: '1:1:10', provider: 'tmdb', title: 'Finale', episodeType: EpisodeType::Finale))->toStored();

    expect($stored)->toBe(['episodeType' => 'finale'])
        // A row with the mark in `extra` reads it under the same key.
        ->and(ItemMetadata::fromStored(['episodeType' => 'mid_season', 'premiere' => true])->episodeType)->toBe(EpisodeType::MidSeason)
        ->and(ItemMetadata::fromStored(['episodeType' => 'mid_season', 'premiere' => true])->extra)->toBe(['premiere' => true])
        ->and(ItemMetadata::fromStored(['episodeType' => 'special'])->episodeType)->toBeNull();
});

test('a book keeps its authors on its row, and a row with them in extra reads them the same', function () {
    $stored = ItemMetadata::fromSummary(new MediaSummary(type: 'book', ref: '1', provider: 'hardcover', title: 'Dune', authors: ['Frank Herbert']))->toStored();

    expect($stored)->toBe(['authors' => ['Frank Herbert']])
        ->and(ItemMetadata::fromStored(['authors' => ['Frank Herbert'], 'pages' => 412])->authors)->toBe(['Frank Herbert'])
        ->and(ItemMetadata::fromStored(['authors' => ['Frank Herbert'], 'pages' => 412])->extra)->toBe(['pages' => 412]);
});

test('a film keeps its collection and Wikidata item on its row, also read from extra', function () {
    $stored = ['wikidataId' => 'Q83495', 'collection' => ['ref' => '2344', 'name' => 'The Matrix Collection', 'posterPath' => null, 'backdropPath' => '/b.jpg'], 'budget' => 63000000];
    $metadata = ItemMetadata::fromStored($stored);

    expect($metadata->wikidataId)->toBe('Q83495')
        ->and($metadata->collection?->name)->toBe('The Matrix Collection')
        ->and($metadata->extra)->toBe(['budget' => 63000000])
        ->and($metadata->toStored())->toEqual($stored)
        // A copy that no longer reads as a collection is dropped, not fatal.
        ->and(ItemMetadata::fromStored(['collection' => ['name' => 'No ref']])->collection)->toBeNull();
});

test('a film leaves cinemas on its earliest digital or disc release, wherever it is', function () {
    $metadata = ItemMetadata::fromDetails(new MediaDetails(
        summary: new MediaSummary(type: 'movie', ref: '1', provider: 'test', title: 'Film'),
        releases: [
            new ReleaseEntry('US', FilmReleaseType::Theatrical, '1999-03-31'),
            new ReleaseEntry('US', FilmReleaseType::Disc, '1999-11-02'),
            new ReleaseEntry('RO', FilmReleaseType::Digital, '1999-08-15'),
        ],
    ));

    expect($metadata->homeReleaseDate())->toBe('1999-08-15')
        ->and(ItemMetadata::fromStored(null)->homeReleaseDate())->toBeNull();
});

test('a show comes from its countries of origin, a film from the countries that produced it', function () {
    $show = ItemMetadata::fromStored(['originCountry' => ['JP'], 'productionCountries' => ['US', 'JP']]);
    $film = ItemMetadata::fromStored(['productionCountries' => ['GB', 'US']]);

    expect($show->originCountries())->toBe(['JP'])
        ->and($film->originCountries())->toBe(['GB', 'US'])
        ->and(ItemMetadata::fromStored(null)->originCountries())->toBe([]);
});

test('a show\'s networks read back as network links, a nameless one skipped', function () {
    $metadata = ItemMetadata::fromStored(['networks' => [
        ['name' => 'Showcase', 'ref' => '49', 'provider' => 'tmdb', 'logoPath' => '/logo.png'],
        ['ref' => '50'],
    ]]);

    expect($metadata->networks())->toHaveCount(1)
        ->and($metadata->networks()[0]->name)->toBe('Showcase')
        ->and($metadata->networks()[0]->kind)->toBe(CreatorKind::Network)
        ->and($metadata->networks()[0]->logoPath)->toBe('/logo.png')
        ->and($metadata->studios())->toBe([]);
});
