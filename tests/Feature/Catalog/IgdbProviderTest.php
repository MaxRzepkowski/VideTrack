<?php

use App\Catalog\CatalogueShelf;
use App\Catalog\CompanyRole;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\AgeRatingData;
use App\Catalog\Data\CatalogLink;
use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\CreatorLink;
use App\Catalog\Data\GenreOption;
use App\Catalog\Data\LanguageSupport;
use App\Catalog\Data\MultiplayerSupport;
use App\Catalog\Data\PlatformRelease;
use App\Catalog\Data\ProviderRating;
use App\Catalog\Data\RelatedRelease;
use App\Catalog\Data\StoreLink;
use App\Catalog\DatePrecision;
use App\Catalog\GameKind;
use App\Catalog\GameStore;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MatureContent;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\MultiplayerMode;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRegistry;
use App\Catalog\Providers\Igdb\IgdbClient;
use App\Catalog\Providers\Igdb\IgdbGames;
use App\Catalog\Providers\Igdb\IgdbProvider;
use App\Catalog\ReleaseRegion;
use App\Catalog\TitleStatus;
use App\Catalog\VideoKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

function igdbProvider(): IgdbProvider
{
    return app(IgdbProvider::class);
}

test('the games module does not exist without IGDB credentials', function () {
    expect(app(MediaTypeRegistry::class)->has('game'))->toBeFalse()
        ->and(fn () => app(ProviderRegistry::class)->get('igdb'))->toThrow(InvalidArgumentException::class);
});

test('the game type registers once IGDB credentials are configured', function () {
    enableGamesModule();

    $definition = app(MediaTypeRegistry::class)->get('game');

    expect($definition->playable)->toBeTrue()
        ->and($definition->searchable)->toBeTrue()
        ->and($definition->isRoot())->toBeTrue()
        ->and($definition->provider)->toBe('igdb');
});

test('search maps game summaries with absolute cover urls', function () {
    enableGamesModule();
    Http::fake(['*/games' => Http::response(jsonFixture('igdb/search'))]);

    $results = igdbProvider()->search('hollow knight', 'en', MatureContent::Suggestive);

    expect($results)->toHaveCount(2)
        ->and($results[0]->type)->toBe('game')
        ->and($results[0]->ref)->toBe('14593')
        ->and($results[0]->title)->toBe('Hollow Knight')
        ->and($results[0]->year)->toBe(2017)
        ->and($results[0]->posterPath)->toBe('https://images.igdb.com/igdb/image/upload/t_cover_big_2x/co1rgi.jpg')
        ->and($results[0]->communityRating)->toBe(9.2);
});

test('a search lifts the games people know above names that only sound closer', function () {
    enableGamesModule();
    // As IGDB answers "cyberpunk": by closeness of the name alone.
    $game = fn (int $id, string $name, int $ratings = 0, int $hypes = 0): array => [
        'id' => $id, 'name' => $name, 'cover' => ['image_id' => "c{$id}"], 'total_rating_count' => $ratings, 'hypes' => $hypes,
    ];
    Http::fake(['*/games' => Http::response([
        $game(1, 'Cyberpunk Sex'),
        $game(2, 'Cyberpunk'),
        $game(3, 'Cyberpunk 2', hypes: 9),
        $game(4, 'Cyberpunk 2077', ratings: 1689, hypes: 1038),
        $game(5, 'Cyberpunk Madness'),
        ['id' => 6, 'name' => 'VA-11 Hall-A: Cyberpunk Bartender Action', 'cover' => ['image_id' => 'c6'], 'total_rating_count' => 159,
            'alternative_names' => [['name' => 'VA-11 HALL-A', 'comment' => 'Stylized title'], ['name' => 'Valhalla', 'comment' => 'Alternative title'], ['name' => 'va11halla.exe', 'comment' => 'Windows Executable']]],
    ])]);

    $results = igdbProvider()->search('cyberpunk', 'en', MatureContent::Suggestive);

    expect(array_map(fn ($summary): string => $summary->title, $results))->toBe([
        'Cyberpunk 2077', 'VA-11 Hall-A: Cyberpunk Bartender Action', 'Cyberpunk 2', 'Cyberpunk Sex', 'Cyberpunk', 'Cyberpunk Madness',
    ])
        // Its other names ride along for whoever places it by name; the executable and the logo's capitals do not.
        ->and($results[1]->alternativeTitles)->toBe(['Valhalla']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'total_rating_count') && str_contains($request->body(), 'limit '.IgdbProvider::SEARCH_POOL.';'));
});

test('game details map igdb fields to the shared shape', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'), [['id' => 951, 'game_id' => 7346, 'normally' => 332160]])),
    ]);

    $game = igdbProvider()->details('game', '7346', 'en');

    expect($game->summary->title)->toBe('The Legend of Zelda: Breath of the Wild')
        ->and($game->summary->year)->toBe(2017)
        ->and($game->summary->communityRating)->toBe(9.5)
        ->and($game->runtime)->toBe(5536)
        ->and($game->genres)->toBe(['Puzzle', 'Adventure'])
        ->and($game->status)->toBe(TitleStatus::Released)
        // The chip is the board of the locale's country (US) until a region is given.
        ->and($game->certification)->toBe('ESRB E10+')
        ->and(igdbProvider()->details('game', '7346', 'en', 'PL')->certification)->toBe('PEGI 12')
        ->and(array_map(fn ($keyword): array => $keyword->jsonSerialize(), $game->keywords))->toBe([
            ['name' => 'Action', 'ref' => null],
            ['name' => 'Open world', 'ref' => null],
            ['name' => 'exploration', 'ref' => null],
        ])
        ->and(array_map(fn (CreatorLink $link): string => $link->name, $game->extra['developerLinks']))->toBe(['Nintendo EPD Production Group No. 3'])
        ->and(array_map(fn (CreatorLink $link): string => $link->name, $game->extra['publisherLinks']))->toBe(['Nintendo'])
        // A port's studio and a supporting one are credits of their own, never developers.
        ->and(array_map(fn (CreatorLink $link): string => $link->name, $game->extra['portingLinks']))->toBe(['Example Port Studio'])
        ->and(array_map(fn (CreatorLink $link): string => $link->name, $game->extra['supportingLinks']))->toBe(['Example Support Studio'])
        // Beside a summary the storyline is its own text; alone it stands as the overview.
        ->and($game->extra['storyline'])->toBe('Link awakens from a deep slumber.')
        ->and($game->extra['platforms'])->toBe(['Wii U', 'Nintendo Switch'])
        ->and($game->externalLinks['homepage'])->toBe('http://www.zelda.com/breath-of-the-wild/')
        ->and($game->externalLinks['igdb'])->toBe('https://www.igdb.com/games/the-legend-of-zelda-breath-of-the-wild')
        ->and($game->externalLinks)->not->toHaveKey('twitter');
});

test('game dlcs merge dlc and expansions, newest first', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    $dlcs = igdbProvider()->details('game', '7346', 'en')->extra['dlcs'];

    expect($dlcs)->toHaveCount(2)
        ->and($dlcs[0]->type)->toBe('game')
        ->and($dlcs[0]->title)->toBe('The Champions Ballad')
        // A non-main game_type rides along so the card can badge it.
        ->and($dlcs[0]->extra['gameType'])->toBe('expansion')
        ->and($dlcs[1]->title)->toBe('The Master Trials')
        ->and($dlcs[1]->extra['gameType'])->toBe('dlc');
});

test('game details list the dated dlc, expansions, remakes and remasters, newest first, with their own releases', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    $related = igdbProvider()->details('game', '7346', 'en')->relatedReleases;

    // The remake has no date yet: nothing a calendar could show.
    expect(array_map(fn (RelatedRelease $release): array => [$release->ref, $release->kind, $release->date], $related ?? []))->toBe([
        ['5003', GameKind::Remaster, '2027-03-12'],
        ['5001', GameKind::Expansion, '2017-12-07'],
        ['5000', GameKind::Dlc, '2017-06-23'],
    ])
        ->and($related[0]->title)->toBe('Breath of the Wild HD')
        ->and($related[0]->posterPath)->toBeNull()
        ->and($related[0]->platformReleases)->toEqual([
            new PlatformRelease('2027-03-12', 'Switch 2', 'Early Access', ReleaseRegion::Europe),
            new PlatformRelease('2027-12-31', 'Switch 2', null, ReleaseRegion::Worldwide, DatePrecision::Year),
        ])
        ->and($related[1]->platformReleases)->toBe([])
        ->and($related[2]->posterPath)->toContain('codlc1')
        ->and($related[2]->platformReleases)->toHaveCount(2);
});

test('the details query asks for the releases of every related game it lists', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    igdbProvider()->details('game', '7346', 'en');

    Http::assertSent(fn (Request $request): bool => collect(['dlcs', 'expansions', 'standalone_expansions', 'remakes', 'remasters'])
        ->every(fn (string $relation): bool => str_contains($request->body(), "{$relation}.release_dates.date_format.format")));
});

test('game other releases and parent game are exposed', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    $game = igdbProvider()->details('game', '7346', 'en');

    expect($game->extra['otherReleases'])->toHaveCount(1)
        ->and($game->extra['otherReleases'][0]->title)->toBe('Breath of the Wild + Expansion Pass')
        ->and($game->extra['otherReleases'][0]->extra['gameType'])->toBe('bundle')
        ->and($game->extra['parentGameRef'])->toBe('9000')
        ->and($game->extra['parentGameTitle'])->toBe('The Legend of Zelda');
});

test('game details expose linkable studios carrying provider refs', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    $game = igdbProvider()->details('game', '7346', 'en');

    expect($game->extra['developerLinks'])->toHaveCount(1)
        ->and($game->extra['developerLinks'][0]->name)->toBe('Nintendo EPD Production Group No. 3')
        ->and($game->extra['developerLinks'][0]->kind)->toBe(CreatorKind::Company)
        ->and($game->extra['developerLinks'][0]->ref)->toBe('45209')
        ->and($game->extra['developerLinks'][0]->provider)->toBe('igdb')
        ->and($game->extra['publisherLinks'][0]->name)->toBe('Nintendo')
        ->and($game->extra['publisherLinks'][0]->ref)->toBe('70');
});

test('the company page maps igdb fields and top games per role as credits, all in one request', function () {
    enableGamesModule();
    $bodies = [];

    // The record and the first chunk of the ludography — the released back
    // catalogue (sort total_rating) and the upcoming front (first_release_date
    // >= today) — come in one multiquery; role (from involved_companies) and
    // category (from game_type) are bucketed in PHP.
    Http::fake([
        '*/multiquery' => function ($request) use (&$bodies) {
            $bodies[] = $request->body();

            return Http::response(igdbCompanyPage(jsonFixture('igdb/company'), rated: [
                [
                    'id' => 7346, 'name' => 'The Legend of Zelda: Breath of the Wild',
                    'first_release_date' => 1488499200, 'total_rating' => 95.0, 'game_type' => 0, 'cover' => ['image_id' => 'co3p2d'],
                    'involved_companies' => [['company' => ['id' => 70], 'developer' => true, 'publisher' => false]],
                ],
                [
                    'id' => 1234, 'name' => 'Super Mario Odyssey',
                    'first_release_date' => 1508976000, 'total_rating' => 92.0, 'game_type' => 0, 'cover' => ['image_id' => 'co1mxf'],
                    'involved_companies' => [['company' => ['id' => 70], 'developer' => false, 'publisher' => true]],
                ],
                [
                    'id' => 5000, 'name' => 'The Master Trials',
                    'first_release_date' => 1498176000, 'total_rating' => 80.0, 'game_type' => 1, 'cover' => ['image_id' => 'codlc1'],
                    'involved_companies' => [['company' => ['id' => 70], 'developer' => true, 'publisher' => false]],
                ],
                [
                    'id' => 6000, 'name' => 'Coverless Game',
                    'first_release_date' => 1527811200, 'total_rating' => 70.0, 'game_type' => 0,
                    'involved_companies' => [['company' => ['id' => 70], 'developer' => false, 'publisher' => true]],
                ],
                [
                    'id' => 7000, 'name' => 'Ported Game',
                    'first_release_date' => 1527811200, 'total_rating' => 60.0, 'game_type' => 0, 'cover' => ['image_id' => 'coport'],
                    'involved_companies' => [['company' => ['id' => 70], 'developer' => false, 'publisher' => false, 'porting' => true, 'supporting' => true]],
                ],
            ]));
        },
    ]);

    $company = igdbProvider()->company('70', 'en', MatureContent::Suggestive);

    expect($company->ref)->toBe('70')
        ->and($company->name)->toBe('Nintendo')
        ->and($company->logoPath)->toContain('t_logo_med')
        ->and($company->founded)->toBe('2001-09-09')
        ->and($company->country)->toBe('JP')
        ->and($company->parentName)->toBe('Parent Company')
        ->and($company->parentRef)->toBe('51')
        ->and($company->externalLinks['homepage'])->toBe('https://www.nintendo.com')
        ->and($company->externalLinks['igdb'])->toBe('https://www.igdb.com/companies/nintendo')
        ->and($company->credits)->toHaveCount(6);

    $devGame = collect($company->credits)->first(fn ($c) => $c->role === CompanyRole::Developer && $c->category === CatalogueShelf::Game);
    $pubGame = collect($company->credits)->first(fn ($c) => $c->role === CompanyRole::Publisher && $c->category === CatalogueShelf::Game);
    $devDlc = collect($company->credits)->first(fn ($c) => $c->category === CatalogueShelf::Dlc);
    $coverlessGame = collect($company->credits)->first(fn ($c) => $c->media->ref === '6000');

    expect($devGame->media->title)->toBe('The Legend of Zelda: Breath of the Wild')
        ->and($devGame->media->type)->toBe('game')
        ->and($pubGame->media->title)->toBe('Super Mario Odyssey')
        ->and($devDlc->media->title)->toBe('The Master Trials')
        ->and($devDlc->role)->toBe(CompanyRole::Developer)
        ->and($coverlessGame->media->posterPath)->toBeNull()
        // Porting and supporting are roles of their own, with their own shelves.
        ->and(collect($company->credits)->where('media.ref', '7000')->pluck('role')->all())->toBe([CompanyRole::Porting, CompanyRole::Supporting])
        ->and($bodies)->toHaveCount(1)
        ->and(substr_count($bodies[0], 'query games'))->toBe(2)
        ->and($bodies[0])->not->toContain('cover != null');
});

/** A game of the company's ludography, its developer. */
function companyGame(int $id): array
{
    return ['id' => $id, 'name' => "Game {$id}", 'first_release_date' => 1488499200, 'game_type' => 0, 'involved_companies' => [['company' => ['id' => 70], 'developer' => true]]];
}

test('a later chunk of the ludography is the back catalogue alone, one request past the chunks before', function () {
    enableGamesModule();
    Http::fake(['*/games' => Http::response(array_map(companyGame(...), range(1, 500)))]);

    $chunk = igdbProvider()->companyCredits('70', 'en', MatureContent::Suggestive, 2);

    expect($chunk->credits)->toHaveCount(500)
        ->and($chunk->hasMore)->toBeTrue()
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'api.igdb.com')))->toHaveCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/games')
        && str_contains($request->body(), 'offset 500;')
        && ! str_contains($request->body(), 'first_release_date >='));
});

test('the first chunk of the ludography asks the upcoming front and the back catalogue together', function () {
    enableGamesModule();
    Http::fake(['*/multiquery' => Http::response(igdbCompanyPage([], upcoming: [companyGame(1)], rated: [companyGame(1), companyGame(2)]))]);

    $chunk = igdbProvider()->companyCredits('70', 'en', MatureContent::Suggestive, 1);

    // A game in both answers is one credit; fewer rated than a chunk holds is the last chunk.
    expect(array_map(fn ($credit) => $credit->media->ref, $chunk->credits))->toBe(['1', '2'])
        ->and($chunk->hasMore)->toBeFalse()
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'api.igdb.com')))->toHaveCount(1);
});

test('game details persist every regional release variant with unfiltered statuses', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    // Both WiiU regional dates survive (the calendar picks per user
    // preference later); the next-gen patch too — statuses are
    // deliberately unfiltered. A plain release carries no status.
    expect(igdbProvider()->details('game', '7346', 'en')->platformReleases)->toEqual([
        new PlatformRelease('2017-03-02', 'WiiU', null, ReleaseRegion::Europe),
        new PlatformRelease('2017-03-03', 'WiiU', null, ReleaseRegion::NorthAmerica),
        new PlatformRelease('2017-03-03', 'Switch', null, ReleaseRegion::Worldwide),
        new PlatformRelease('2030-01-01', 'Switch 2', 'Next-Gen Optimization Patch Release', ReleaseRegion::Worldwide),
    ]);
});

test('a release IGDB leaves unlabelled is the same plain release as a "Full Release"', function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    // The same Switch premiere listed twice, once without a status: one release.
    $game[0]['release_dates'][] = [...collect($game[0]['release_dates'])->firstWhere('platform.abbreviation', 'Switch'), 'status' => null];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    $switch = array_values(array_filter(
        igdbProvider()->details('game', '7346', 'en')->platformReleases,
        fn (PlatformRelease $release): bool => $release->platform === 'Switch',
    ));

    expect($switch)->toEqual([new PlatformRelease('2017-03-03', 'Switch', null, ReleaseRegion::Worldwide)]);
});

test('a date IGDB knows only to its year, quarter or month says so, the first date as its best-known release', function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    // The Witcher IV, as IGDB answers it: "2028" stamped with the year's
    // last day on every platform, the first date copied from them.
    $year = CarbonImmutable::parse('2028-12-31', 'UTC')->getTimestamp();
    $game[0]['first_release_date'] = $year;
    $game[0]['release_dates'] = [
        ['id' => 1, 'date' => $year, 'date_format' => ['id' => 2, 'format' => 'YYYY'], 'human' => '2028', 'platform' => ['abbreviation' => 'PC'], 'release_region' => 8],
        ['id' => 2, 'date' => CarbonImmutable::parse('2029-03-31', 'UTC')->getTimestamp(), 'date_format' => ['id' => 3, 'format' => 'YYYYQ1'], 'platform' => ['abbreviation' => 'PS5'], 'release_region' => 8],
        ['id' => 3, 'date' => CarbonImmutable::parse('2029-05-01', 'UTC')->getTimestamp(), 'date_format' => ['id' => 1, 'format' => 'YYYYMM'], 'platform' => ['abbreviation' => 'XSX'], 'release_region' => 8],
        // Not dated at all: no release to keep.
        ['id' => 4, 'date_format' => ['id' => 7, 'format' => 'TBD'], 'human' => 'TBD', 'platform' => ['abbreviation' => 'Switch 2'], 'release_region' => 8],
    ];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    $details = igdbProvider()->details('game', '7346', 'en');

    expect($details->summary->releaseDate)->toBe('2028-12-31')
        ->and($details->summary->releasePrecision)->toBe(DatePrecision::Year)
        ->and($details->platformReleases)->toEqual([
            new PlatformRelease('2028-12-31', 'PC', null, ReleaseRegion::Worldwide, DatePrecision::Year),
            new PlatformRelease('2029-03-31', 'PS5', null, ReleaseRegion::Worldwide, DatePrecision::Quarter),
            new PlatformRelease('2029-05-01', 'XSX', null, ReleaseRegion::Worldwide, DatePrecision::Month),
        ]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'release_dates.date_format.format'));
});

test('one exact release on the first date makes the day real', function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    $game[0]['release_dates'][] = [...$game[0]['release_dates'][0], 'id' => 9, 'platform' => ['abbreviation' => 'PC'], 'date_format' => ['id' => 2, 'format' => 'YYYY']];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    expect(igdbProvider()->details('game', '7346', 'en')->summary->releasePrecision)->toBe(DatePrecision::Day);
});

test('the game status comes from game_status, the retired status field means nothing', function (array $fields, ?TitleStatus $status) {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    unset($game[0]['game_status']);
    $game[0] = [...$game[0], ...$fields];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    expect(igdbProvider()->details('game', '7346', 'en')->status)->toBe($status);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'game_status.status') && ! preg_match('/[ ,]status,/', $request->body()));
})->with([
    'named in game_status' => [['game_status' => ['id' => 4, 'status' => 'Early Access'], 'status' => 6], TitleStatus::EarlyAccess],
    'cancelled, as IGDB spells it' => [['game_status' => ['id' => 6, 'status' => 'Cancelled']], TitleStatus::Canceled],
    'only the retired field' => [['status' => 6], null],
]);

test('a game says the languages it speaks, how it is played together and from where, and who scored it', function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    $speaks = fn (string $locale, string $type): array => ['language' => ['locale' => $locale], 'language_support_type' => ['name' => $type]];
    $game[0] = [...$game[0],
        'language_supports' => [$speaks('en-US', 'Audio'), $speaks('en-US', 'Interface'), $speaks('pl-PL', 'Subtitles'), $speaks('pl-PL', 'Interface'), $speaks('xx', 'Braille')],
        // Two platforms, the second allowing more players; a single-player
        // count of one names no multiplayer.
        'multiplayer_modes' => [
            ['onlinecoop' => true, 'onlinecoopmax' => 2, 'splitscreen' => false, 'offlinemax' => 1],
            ['onlinecoop' => true, 'onlinecoopmax' => 4, 'splitscreen' => true, 'dropin' => true, 'onlinemax' => 8],
        ],
        'player_perspectives' => [['name' => 'Third person'], ['name' => 'Bird view / Isometric']],
        'rating' => 91.24, 'rating_count' => 1203,
        'aggregated_rating' => 97.5, 'aggregated_rating_count' => 0,
    ];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    $extra = igdbProvider()->details('game', '7346', 'en')->extra;

    expect($extra['languages'])->toEqual([
        new LanguageSupport('en-US', audio: true, interface: true),
        new LanguageSupport('pl-PL', subtitles: true, interface: true),
    ])
        ->and($extra['multiplayer'])->toEqual([
            new MultiplayerSupport(MultiplayerMode::OnlineCoop, 4),
            new MultiplayerSupport(MultiplayerMode::DropIn),
            new MultiplayerSupport(MultiplayerMode::SplitScreen),
            new MultiplayerSupport(MultiplayerMode::Online, 8),
        ])
        ->and($extra['perspectives'])->toBe(['Third person', 'Bird view / Isometric'])
        ->and($extra['playersRating'])->toEqual(new ProviderRating(9.1, 1203))
        // A score nobody is counted behind is no critics' score.
        ->and($extra['criticsRating'])->toBeNull();
});

test("a game's stores come from its store entries, then its websites, and leave its other links", function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    $entry = fn (string $source, ?string $url): array => ['external_game_source' => ['name' => $source], 'url' => $url];
    $game[0]['external_games'] = [
        $entry('Steam', 'https://store.steampowered.com/app/1'),
        $entry('Twitch', 'https://www.twitch.tv/directory/category/x'),
        $entry('Amazon', 'https://www.amazon.com/dp/x'),
        $entry('GOG', null),
        $entry('Xbox Game Pass Ultimate Cloud', 'https://www.xbox.com/play/games/x'),
    ];
    $game[0]['websites'] = [
        ['type' => 13, 'url' => 'https://store.steampowered.com/app/1/other'],
        ['type' => 24, 'url' => 'https://www.nintendo.com/store/products/x'],
        ['type' => 3, 'url' => 'https://en.wikipedia.org/wiki/X'],
    ];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    $details = igdbProvider()->details('game', '7346', 'en');

    expect($details->extra['stores'])->toEqual([
        new StoreLink(GameStore::Steam, 'https://store.steampowered.com/app/1'),
        new StoreLink(GameStore::Nintendo, 'https://www.nintendo.com/store/products/x'),
        new StoreLink(GameStore::GamePass, 'https://www.xbox.com/play/games/x'),
    ])
        ->and(array_keys($details->externalLinks))->toBe(['wikipedia', 'igdb'])
        ->and($details->extra['stores'][2]->jsonSerialize()['subscription'])->toBeTrue();
});

test("every board's age rating comes with the content behind it, the chip picking the viewer's board", function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    $game[0]['age_ratings'] = [
        [
            'organization' => ['name' => 'ESRB'], 'rating_category' => ['rating' => 'M'],
            'rating_content_descriptions' => [['description' => 'Blood and Gore'], ['description' => 'Strong Language'], ['description' => 'Blood and Gore']],
            'synopsis' => 'This is an action role-playing game.',
        ],
        ['organization' => ['name' => 'PEGI'], 'rating_category' => ['rating' => '18']],
        ['organization' => ['name' => 'CLASS_IND'], 'rating_category' => ['rating' => '16']],
        ['organization' => ['name' => 'USK']],
    ];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    $details = igdbProvider()->details('game', '7346', 'pt-BR', 'BR');

    expect($details->extra['ageRatings'])->toEqual([
        new AgeRatingData('ESRB', 'M', ['Blood and Gore', 'Strong Language'], 'This is an action role-playing game.'),
        new AgeRatingData('PEGI', '18'),
        new AgeRatingData('ClassInd', '16'),
    ])
        ->and($details->certification)->toBe('ClassInd 16');
});

test('a game card says how many await it', function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    $game[0]['hypes'] = 289;
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    expect(igdbProvider()->details('game', '7346', 'en')->summary->extra['awaitedBy'] ?? null)->toBe(289);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'hypes'));
});

test('a bundle shows what it holds and links up to the one game it is built around', function (array $contents, ?string $parentRef) {
    enableGamesModule();
    $bundle = jsonFixture('igdb/game');
    $bundle[0] = [...$bundle[0], 'id' => 277807, 'name' => 'Cyberpunk 2077: Ultimate Edition', 'game_type' => 3];
    unset($bundle[0]['parent_game']);
    Http::fake([
        // IGDB records a bundle from the inside: each game in it lists the bundle.
        '*/multiquery' => fn (Request $request) => Http::response(igdbGamePage(
            $bundle,
            // A row without an id links nowhere, nor is it the game the bundle is built around.
            bundleContents: str_contains($request->body(), 'where bundles = (277807)') ? [...$contents, ['name' => 'No id', 'game_type' => 0]] : [],
        )),
    ]);

    $extra = igdbProvider()->details('game', '277807', 'en')->extra;

    expect(array_map(fn ($summary): string => $summary->title, $extra['bundleContents']))->toBe(array_column($contents, 'name'))
        ->and($extra['parentGameRef'])->toBe($parentRef);
})->with([
    'a game and its expansion' => [[
        ['id' => 1877, 'name' => 'Cyberpunk 2077', 'game_type' => 0, 'cover' => ['image_id' => 'a']],
        ['id' => 199735, 'name' => 'Cyberpunk 2077: Phantom Liberty', 'game_type' => 2, 'cover' => ['image_id' => 'b']],
    ], '1877'],
    'a compilation of games' => [[
        ['id' => 1, 'name' => 'The Witcher', 'game_type' => 0, 'cover' => ['image_id' => 'a']],
        ['id' => 2, 'name' => 'The Witcher 2', 'game_type' => 0, 'cover' => ['image_id' => 'b']],
    ], null],
]);

test('a game that is no bundle holds nothing, whatever lists it', function () {
    enableGamesModule();
    $listing = [['id' => 1, 'name' => 'A compilation', 'game_type' => 3, 'cover' => ['image_id' => 'a']]];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'), bundleContents: $listing)),
    ]);

    $extra = igdbProvider()->details('game', '7346', 'en')->extra;

    expect($extra['bundleContents'])->toBeNull()
        ->and($extra['parentGameRef'])->toBe('9000');
});

test("a game's page is one request to IGDB", function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'), [['id' => 951, 'game_id' => 7346, 'normally' => 332160]])),
    ]);

    igdbProvider()->details('game', '7346', 'en');

    $sent = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.igdb.com'))->values();
    expect($sent)->toHaveCount(1)
        ->and($sent[0][0]->url())->toEndWith('/v4/multiquery')
        ->and($sent[0][0]->body())->toContain(
            'query games "'.IgdbGames::GAME.'" {',
            'query game_time_to_beats "'.IgdbGames::TIME_TO_BEAT.'" {',
            'query games "'.IgdbGames::EDITIONS.'" {',
            'query games "'.IgdbGames::BUNDLE_CONTENTS.'" {',
        );
});

test('an answer missing one of the asked results is a failure, and is not kept', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::sequence()
            ->push(array_values(array_filter(igdbGamePage(jsonFixture('igdb/game')), fn (array $result): bool => $result['name'] !== IgdbGames::BUNDLE_CONTENTS)))
            ->push(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    expect(fn () => igdbProvider()->details('game', '7346', 'en'))->toThrow(ProviderException::class, 'IGDB multiquery answered without ['.IgdbGames::BUNDLE_CONTENTS.'].')
        ->and(igdbProvider()->details('game', '7346', 'en')->summary->title)->toBe('The Legend of Zelda: Breath of the Wild');
});

test('a multiquery past the limit, or requiring a result it does not ask, is a mistake in the code', function (array $queries, ?string $required) {
    enableGamesModule();
    Http::fake();

    expect(fn () => app(IgdbClient::class)->multiquery($queries, 60, $required))->toThrow(LogicException::class);
    Http::assertNothingSent();
})->with([
    'eleven queries' => [array_fill_keys(array_map(fn (int $n): string => "q{$n}", range(1, 11)), ['games', 'fields name;']), null],
    'an unknown required result' => [['game' => ['games', 'fields name;']], 'gaem'],
]);

test('an edition lists no editions, whatever IGDB lists beside it', function () {
    enableGamesModule();
    $edition = jsonFixture('igdb/game');
    $edition[0] = [...$edition[0], 'id' => 70, 'version_parent' => ['id' => 7346, 'name' => 'The Legend of Zelda: Breath of the Wild']];
    $listing = [['id' => 71, 'name' => 'Breath of the Wild - Complete Edition', 'cover' => ['image_id' => 'e71']]];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($edition, editions: $listing)),
    ]);

    $extra = igdbProvider()->details('game', '70', 'en')->extra;

    expect($extra['editions'])->toBeNull();
});

test('an empty answer for the game is not kept either: the next look asks again', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::sequence()
            ->push(igdbGamePage([]))
            ->push(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    expect(fn () => igdbProvider()->details('game', '7346', 'en'))->toThrow(ItemNotFoundException::class)
        ->and(igdbProvider()->details('game', '7346', 'en')->summary->title)->toBe('The Legend of Zelda: Breath of the Wild');
});

test('a game lists its editions, and an edition links up to its game', function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    unset($game[0]['parent_game']);
    $edition = fn (int $id, string $name): array => ['id' => $id, 'name' => $name, 'cover' => ['image_id' => "e{$id}"]];
    Http::fake([
        '*/multiquery' => fn (Request $request) => Http::response(igdbGamePage(
            $game,
            // A game is never an edition of itself, whatever an answer says.
            editions: str_contains($request->body(), 'where version_parent = 7346')
                // Nor is a row without an id anything to link to.
                ? [$edition(70, 'Breath of the Wild - Deluxe Edition'), $edition(7346, 'Itself'), ['name' => 'No id'], $edition(71, 'Breath of the Wild - Complete Edition')]
                : [],
        )),
    ]);

    $extra = igdbProvider()->details('game', '7346', 'en')->extra;

    expect(array_map(fn ($summary): string => $summary->title, $extra['editions']))->toBe(['Breath of the Wild - Deluxe Edition', 'Breath of the Wild - Complete Edition'])
        ->and($extra['parentGameRef'])->toBeNull();

});

test("a game's series is the subseries it continues, not the line above it", function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    // Shadow of the Tomb Raider, as IGDB answers: the reboot trilogy is a
    // subseries of the whole line.
    $game[0]['collections'] = [
        ['id' => 183, 'name' => 'Tomb Raider', 'as_child_relations' => []],
        ['id' => 9209, 'name' => 'Tomb Raider: Survivor', 'as_child_relations' => [['id' => 5, 'parent_collection' => 183]]],
    ];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    expect(igdbProvider()->details('game', '7346', 'en')->collection)->toEqual(new CollectionLink('9209', 'Tomb Raider: Survivor'));
});

test('a series holds the games of its line in release order, announced ones last', function () {
    enableGamesModule();
    $member = fn (int $id, string $name, ?int $date, int $type = 0, ?int $parent = null, int $membership = 1): array => [
        'type' => $membership,
        'game' => ['id' => $id, 'name' => $name, 'first_release_date' => $date, 'game_type' => $type, 'version_parent' => $parent, 'cover' => ['image_id' => "c{$id}"]],
    ];
    Http::fake([
        '*/collections' => Http::response([[
            'id' => 62, 'name' => 'The Witcher',
            'as_child_relations' => [['type' => 1, 'parent_collection' => ['id' => 5, 'name' => 'CD Projekt Red Universe']]],
            'as_parent_relations' => [
                ['type' => 1, 'child_collection' => ['id' => 70, 'name' => 'The Witcher: Enhanced']],
                ['type' => 2, 'child_collection' => ['id' => 71, 'name' => 'Gwent']],
                ['type' => 34, 'child_collection' => ['id' => 72, 'name' => 'A story arc']],
            ],
        ]]),
        '*/collection_memberships' => Http::response([
            $member(1942, 'The Witcher 3: Wild Hunt', 1431993600),
            $member(325594, 'The Witcher IV', null),
            $member(80, 'The Witcher', 1193702400),
            $member(1943, 'The Witcher 3: Wild Hunt - Hearts of Stone', 1444694400, type: 2),
            $member(22439, 'The Witcher 3: Wild Hunt - Game of the Year Edition', 1472601600, parent: 1942),
            $member(478, 'The Witcher 2: Assassins of Kings', 1305590400),
            // A remaster is its game again; it stays off the series, as DLC does.
            $member(8, 'The Witcher 2: Enhanced Edition Remaster', 1335830400, type: 9),
            // A cancelled game never joined the line.
            [...$member(9, 'The Witcher: Rise of the White Wolf', null), 'game' => [...$member(9, 'The Witcher: Rise of the White Wolf', null)['game'], 'game_status' => ['status' => 'Cancelled']]],
        ]),
    ]);

    $series = igdbProvider()->collection('62', 'en');

    expect($series->name)->toBe('The Witcher')
        ->and(array_map(fn ($part): string => $part->title, $series->parts))->toBe([
            'The Witcher', 'The Witcher 2: Assassins of Kings', 'The Witcher 3: Wild Hunt', 'The Witcher IV',
        ])
        ->and($series->posterPath)->toBe('https://images.igdb.com/igdb/image/upload/t_cover_big_2x/c80.jpg')
        // Around it: the series above, those under it by the kind of tie
        // (a story arc is neither); spin-off games are the franchise's, not here.
        ->and($series->relations?->parent)->toEqual(new CollectionLink('5', 'CD Projekt Red Universe'))
        ->and($series->relations?->subseries)->toEqual([new CollectionLink('70', 'The Witcher: Enhanced')])
        ->and($series->relations?->spinOffSeries)->toEqual([new CollectionLink('71', 'Gwent')]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'where collection = 62 & type = 1'));
});

test("a franchise holds its series' games apart from the rest under its name", function () {
    // Three queries, close to the limiter's window under a frozen clock.
    $this->travelBack();
    enableGamesModule();
    $game = fn (int $id, string $name, ?int $date): array => ['id' => $id, 'name' => $name, 'first_release_date' => $date, 'game_type' => 0, 'cover' => ['image_id' => "c{$id}"]];
    Http::fake([
        '*/franchises' => Http::response([['id' => 7, 'name' => 'Resident Evil', 'games' => [1, 2, 3, 4]]]),
        '*/games' => Http::response([
            $game(2, 'Resident Evil 2', 885427200),
            $game(3, 'Resident Evil Survivor', 948326400),
            $game(1, 'Resident Evil', 828316800),
            $game(4, 'Resident Evil Requiem', null),
        ]),
        // Survivor is a spin-off: no line membership of its own.
        '*/collection_memberships' => Http::response([['game' => 1], ['game' => 2], ['game' => 4]]),
    ]);

    $franchise = igdbProvider()->franchise('7', 'en');

    expect($franchise->name)->toBe('Resident Evil')
        ->and(array_map(fn ($game): string => $game->title, $franchise->series))->toBe(['Resident Evil', 'Resident Evil 2', 'Resident Evil Requiem'])
        ->and(array_map(fn ($game): string => $game->title, $franchise->others))->toBe(['Resident Evil Survivor']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'where id = (1,2,3,4) & version_parent = null'));
});

test("a game's page names its franchises, its main one first", function () {
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    $game[0]['franchise'] = ['id' => 7, 'name' => 'Resident Evil'];
    $game[0]['franchises'] = [['id' => 9, 'name' => 'Capcom Classics'], ['id' => 7, 'name' => 'Resident Evil']];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
    ]);

    expect(igdbProvider()->details('game', '7346', 'en')->extra['franchiseLinks'])->toEqual([
        new CatalogLink('7', 'Resident Evil'),
        new CatalogLink('9', 'Capcom Classics'),
    ]);
});

test('a platform says what machine it is, with the games coming and the best rated', function () {
    // Three queries, close to the limiter's window under a frozen clock.
    $this->travelBack();
    enableGamesModule();
    Http::fake([
        '*/platforms' => Http::response([[
            'id' => 167, 'name' => 'PlayStation 5', 'abbreviation' => 'PS5', 'generation' => 9, 'summary' => 'Sony\'s ninth.',
            'platform_logo' => ['image_id' => 'plbl'], 'platform_family' => ['name' => 'PlayStation'], 'platform_type' => ['name' => 'Console'],
        ]]),
        '*/games' => fn (Request $request) => Http::response(str_contains($request->body(), 'sort first_release_date asc')
            ? [['id' => 1, 'name' => 'Coming', 'cover' => ['image_id' => 'a']]]
            : [['id' => 2, 'name' => 'Best', 'cover' => ['image_id' => 'b']]]),
    ]);

    $platform = igdbProvider()->platform('167', 'en');

    expect([$platform->name, $platform->abbreviation, $platform->kind, $platform->family, $platform->generation])->toBe(['PlayStation 5', 'PS5', 'Console', 'PlayStation', 9])
        ->and($platform->logoPath)->toBe('https://images.igdb.com/igdb/image/upload/t_logo_med/plbl.png')
        ->and(array_map(fn ($game): string => $game->title, $platform->upcoming))->toBe(['Coming'])
        ->and(array_map(fn ($game): string => $game->title, $platform->topRated))->toBe(['Best']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'where platforms = (167)'));
});

test("a game's page links each of its platforms", function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    expect(array_map(fn ($link): string => $link->name, igdbProvider()->details('game', '7346', 'en')->extra['platformLinks']))
        ->toBe(array_column(jsonFixture('igdb/game')[0]['platforms'], 'name'));
});

test('a game with none of it says nothing', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    $extra = igdbProvider()->details('game', '7346', 'en')->extra;

    expect([$extra['languages'], $extra['multiplayer'], $extra['perspectives'], $extra['criticsRating'], $extra['playersRating'], $extra['stores']])->toBe([null, null, null, null, null, null]);
});

test('game videos, images and related titles survive mapping', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    $game = igdbProvider()->details('game', '7346', 'en');

    expect($game->videos)->toHaveCount(2)
        ->and($game->videos[0]->key)->toBe('zw47_q9wbBE')
        ->and($game->videos[0]->type)->toBe(VideoKind::Trailer)
        ->and($game->videos[1]->type)->toBe(VideoKind::Clip)
        ->and($game->backdrops)->toHaveCount(3)
        ->and($game->summary->backdropPath)->toContain('t_1080p')
        // Only the coverless similar game is dropped.
        ->and($game->related)->toHaveCount(1)
        ->and($game->related[0]->title)->toBe('The Witcher 3: Wild Hunt')
        ->and($game->runtime)->toBeNull();
});

test('trending restores the popularity ranking order', function () {
    enableGamesModule();
    Http::fake([
        '*/popularity_primitives' => Http::response(jsonFixture('igdb/popularity')),
        '*/games' => Http::response(jsonFixture('igdb/search')),
    ]);

    $trending = igdbProvider()->trending('en');

    // Ranking: 115289 first, 14593 second; 424242 has no games row.
    expect($trending)->toHaveCount(2)
        ->and($trending[0]->ref)->toBe('115289')
        ->and($trending[1]->ref)->toBe('14593');
});

test('genre listings page through the count endpoint', function () {
    enableGamesModule();
    Http::fake([
        '*/games/count' => Http::response(['count' => 45]),
        '*/games' => Http::response(jsonFixture('igdb/search')),
        '*/genres' => Http::response([['id' => 12, 'name' => 'Role-playing (RPG)']]),
    ]);

    $genres = igdbProvider()->genres('game', 'en');
    $listing = igdbProvider()->byGenre('game', '12', 'en', MatureContent::Suggestive, 2);

    expect($genres)->toEqual([new GenreOption('12', 'Role-playing (RPG)')])
        ->and($listing->page)->toBe(2)
        ->and($listing->totalPages)->toBe(3)
        ->and($listing->items)->toHaveCount(2);
});

test('release dates read IGDB epochs in UTC, whatever the app timezone', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'))),
    ]);

    // 1488499200 is 2017-03-03 00:00 UTC, still March 2nd in Los Angeles.
    onClock('America/Los_Angeles', fn () => expect(igdbProvider()->details('game', '7346', 'en')->summary->releaseDate)->toBe('2017-03-03'));
});

test('the upcoming section is asked once a day, not on every render', function () {
    enableGamesModule();
    Http::fake(['*/games' => Http::response(jsonFixture('igdb/search'))]);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 15:30:00', 'UTC'));

    igdbProvider()->upcoming('game', 'en', MatureContent::Suggestive);
    $this->travel(5)->minutes();
    igdbProvider()->upcoming('game', 'en', MatureContent::Suggestive);

    // The cutoff is the start of the UTC day, so the query (and its cache
    // key) stays the same all day long.
    $startOfDay = CarbonImmutable::parse('2026-10-10 00:00:00', 'UTC')->getTimestamp();

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), "first_release_date >= {$startOfDay} "));
    Http::assertSentCount(2); // the Twitch token, then a single games query
});

test('the Twitch token rests encrypted in the cache and is reused from there', function () {
    enableGamesModule();
    Http::fake(['*/games' => Http::response(jsonFixture('igdb/search'))]);

    igdbProvider()->search('zelda', 'en', MatureContent::Suggestive);
    igdbProvider()->search('mario', 'en', MatureContent::Suggestive);

    $stored = Cache::get('igdb:token');

    expect($stored)->not->toContain('testing-token')
        ->and(Crypt::decryptString($stored))->toBe('testing-token');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer testing-token') && str_contains($request->url(), '/games'));
    Http::assertSentCount(3); // one token for two searches
});

test('a token the cache cannot decrypt is asked for again', function () {
    enableGamesModule();
    Http::fake(['*/games' => Http::response(jsonFixture('igdb/search'))]);
    Cache::put('igdb:token', 'plain-token-from-before', 3600);

    igdbProvider()->search('zelda', 'en', MatureContent::Suggestive);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'id.twitch.tv'));
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer plain-token-from-before'));
});

test('a game row without an id is left out of the search', function () {
    enableGamesModule();
    $games = jsonFixture('igdb/search');
    unset($games[1]['id']);
    Http::fake(['*/games' => Http::response($games)]);

    expect(igdbProvider()->search('hollow knight', 'en', MatureContent::Suggestive))->toHaveCount(1);
});
