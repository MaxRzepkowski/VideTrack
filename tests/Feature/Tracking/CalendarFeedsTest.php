<?php

use App\Actions\Library\SyncListMembership;
use App\Catalog\EpisodeBadge;
use App\Models\CatalogEvent;
use App\Models\MediaItem;
use App\Models\User;
use App\Tracking\Surfaces\FeedFilter;
use App\Tracking\Surfaces\IcsFeed;
use App\Tracking\Surfaces\RssFeed;

/** One release of every kind a feed carries, each title on the user's watchlist. */
function feedLibrary(User $user): void
{
    $film = MediaItem::factory()->movie()->create(['provider_ref' => '603', 'title' => 'Bravo Film', 'release_date' => today()->addDays(5)->toDateString()]);
    $game = MediaItem::factory()->game()->create([
        'provider_ref' => '1942',
        'title' => 'Charlie Game',
        'release_date' => '2020-01-01',
        'metadata' => ['platformReleases' => [
            ['date' => today()->addDays(7)->toDateString(), 'platform' => 'PC', 'status' => null, 'region' => 'worldwide'],
            ['date' => today()->addDays(9)->toDateString(), 'platform' => 'PS5', 'status' => 'Early Access', 'region' => 'worldwide'],
        ]],
    ]);
    $show = MediaItem::factory()->show()->create(['provider_ref' => '1399', 'title' => 'Alpha Show']);
    $season = MediaItem::factory()->season($show, 2)->create();
    MediaItem::factory()->episode($season, 1, today()->addDays(3)->toDateString())->create(['title' => 'Opening', 'metadata' => ['episodeBadge' => EpisodeBadge::SeasonPremiere->value]]);
    MediaItem::factory()->episode($season, 2, today()->addDays(10)->toDateString())->create(['title' => 'Middle'])
        ->update(['release_time' => today()->addDays(10)->setTime(1, 0)]);
    CatalogEvent::factory()->create(['ref' => '6045', 'name' => 'Delta Showcase', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]);

    foreach ([$film, $game, $show] as $title) {
        app(SyncListMembership::class)->execute($user, $title, [$user->watchlist()->id]);
    }
}

/** @return list<string> */
function uidsOf(string $ics): array
{
    preg_match_all('/^UID:(.+)$/m', str_replace("\r\n", "\n", $ics), $matches);
    $uids = $matches[1];
    sort($uids);

    return $uids;
}

test('every release keeps the UID calendars already hold', function () {
    enableGamesModule();
    $user = User::factory()->create();
    feedLibrary($user);

    $uids = uidsOf(app(IcsFeed::class)->render($user, new FeedFilter));

    expect($uids)->toBe([
        'episode-1399:2:1--'.today()->addDays(3)->toDateString().'@videtrack',
        'episode-1399:2:2--'.today()->addDays(10)->toDateString().'@videtrack',
        'event-igdb-6045@videtrack',
        'game-1942-PC-'.today()->addDays(7)->toDateString().'@videtrack',
        'game-1942-PS5-Early Access-'.today()->addDays(9)->toDateString().'@videtrack',
        'movie-603--'.today()->addDays(5)->toDateString().'@videtrack',
    ]);
});

test('the feed leaves out a kind of title whose own sources keep it off the calendar', function () {
    enableGamesModule();
    $user = User::factory()->create(['preferences' => ['type_sources' => ['game' => ['calendar' => [], 'countdowns' => ['backlog']]]]]);
    feedLibrary($user);

    expect(uidsOf(app(IcsFeed::class)->render($user, new FeedFilter)))->toBe([
        'episode-1399:2:1--'.today()->addDays(3)->toDateString().'@videtrack',
        'episode-1399:2:2--'.today()->addDays(10)->toDateString().'@videtrack',
        'event-igdb-6045@videtrack',
        'movie-603--'.today()->addDays(5)->toDateString().'@videtrack',
    ]);
});

/** @return list<string> the SUMMARY of every event, unfolded */
function summariesOf(string $ics): array
{
    preg_match_all('/^SUMMARY:(.+)$/m', str_replace(["\r\n ", "\r\n"], ['', "\n"], $ics), $matches);

    return $matches[1];
}

test('the address narrows the feed to its types and to premieres, each release keeping its UID', function () {
    enableGamesModule();
    $user = User::factory()->create();
    feedLibrary($user);
    $token = $user->calendarToken();
    $everything = uidsOf($this->get(route('calendar.feed', ['token' => $token]))->getContent());

    $films = $this->get(route('calendar.feed', ['token' => $token, 'types' => 'movie']))->assertOk()->getContent();
    $premieres = $this->get(route('calendar.feed', ['token' => $token, 'scope' => 'premieres']))->assertOk()->getContent();
    $shows = $this->get(route('calendar.feed', ['token' => $token, 'types' => 'game,show', 'scope' => 'premieres']))->assertOk()->getContent();

    expect(summariesOf($films))->toBe(['Bravo Film'])
        ->and(summariesOf($premieres))->toEqualCanonicalizing(['Alpha Show S02E01 – Opening', 'Bravo Film', 'Charlie Game', 'Charlie Game'])
        ->and(summariesOf($shows))->toEqualCanonicalizing(['Alpha Show S02E01 – Opening', 'Charlie Game', 'Charlie Game'])
        ->and(array_diff(uidsOf($premieres), $everything))->toBe([]);
});

test('a variant the feed does not understand falls back instead of failing', function (array $query) {
    enableGamesModule();
    $user = User::factory()->create();
    feedLibrary($user);

    $body = $this->get(route('calendar.feed', ['token' => $user->calendarToken(), ...$query]))->assertOk()->getContent();

    expect(uidsOf($body))->toHaveCount(6);
})->with([
    'an unknown type' => [['types' => 'vinyl']],
    'a part of a title' => [['types' => 'episode']],
    'an unknown scope' => [['scope' => 'finales']],
    'a list instead of a string' => [['types' => ['movie'], 'scope' => ['premieres']]],
]);

test('a release tells what it is and links to its page, in the language of the account', function () {
    enableGamesModule();
    $user = User::factory()->create(['locale' => 'pl']);
    feedLibrary($user);
    app()->setLocale('en');

    $ics = str_replace("\r\n ", '', app(IcsFeed::class)->render($user, new FeedFilter));

    expect($ics)->toContain('DESCRIPTION:'.__('common.season_premiere', [], 'pl'))
        ->toContain('DESCRIPTION:PS5\nEarly Access')
        ->toContain('URL:'.url(route('episode.details', ['ref' => '1399', 'number' => 2, 'episode' => 1], absolute: false)))
        ->toContain('URL:'.url(route('movie.details', ['ref' => '603'], absolute: false)))
        ->and(app()->getLocale())->toBe('en');
});

test('each variant and language is kept apart in the cache', function () {
    enableGamesModule();
    $user = User::factory()->create();
    feedLibrary($user);
    $token = $user->calendarToken();

    $this->get(route('calendar.feed', ['token' => $token, 'types' => 'movie']))->assertOk();
    expect(uidsOf($this->get(route('calendar.feed', ['token' => $token]))->getContent()))->toHaveCount(6);

    $user->update(['locale' => 'pl']);
    expect($this->get(route('calendar.feed', ['token' => $token]))->getContent())->toContain(__('common.season_premiere', [], 'pl'));
});

test('a source switched off on the tracking page leaves the feeds at once, not when the cache runs out', function () {
    enableGamesModule();
    $user = User::factory()->create();
    feedLibrary($user);
    $token = $user->calendarToken();

    expect(uidsOf($this->get(route('calendar.feed', ['token' => $token]))->getContent()))->toHaveCount(6)
        ->and(rssItems($this->get(route('calendar.rss', ['token' => $token]))->getContent()))->not->toBeEmpty();

    // Every title is on the backlog: switching that row off empties both feeds of releases.
    $this->actingAs($user)->patch(route('settings.preferences.update'), ['calendar_watchlist' => false, 'calendar_events' => false])->assertSessionHasNoErrors();

    expect(uidsOf($this->get(route('calendar.feed', ['token' => $token]))->getContent()))->toBe([])
        ->and(rssItems($this->get(route('calendar.rss', ['token' => $token]))->getContent()))->toBe([]);
});

/** @return list<SimpleXMLElement> */
function rssItems(string $xml): array
{
    $channel = simplexml_load_string($xml);

    expect($channel)->not->toBeFalse();

    return iterator_to_array($channel->channel->item, false);
}

test('the RSS channel names its language as a tag a reader understands', function () {
    $user = User::factory()->create(['locale' => 'pt_BR']);

    $channel = simplexml_load_string((string) $this->get(route('calendar.rss', ['token' => $user->calendarToken()]))->getContent());

    expect((string) $channel?->channel->language)->toBe('pt-BR');
});

test("a blocked account's feeds are not there", function () {
    $user = User::factory()->blocked()->create();

    $this->get(route('calendar.feed', ['token' => $user->calendarToken()]))->assertNotFound();
    $this->get(route('calendar.rss', ['token' => $user->calendarToken()]))->assertNotFound();
});

test('the RSS channel carries the premieres as dated items under the iCal UIDs, newest first', function () {
    enableGamesModule();
    $user = User::factory()->create();
    feedLibrary($user);
    $token = $user->calendarToken();

    $response = $this->get(route('calendar.rss', ['token' => $token]))->assertOk();
    $everything = uidsOf($this->get(route('calendar.feed', ['token' => $token]))->getContent());

    expect($response->headers->get('Content-Type'))->toContain('application/rss+xml');

    $items = rssItems($response->getContent());
    $date = today()->addDays(3)->locale('en')->isoFormat('LL');

    expect(array_map(fn (SimpleXMLElement $item): string => (string) $item->title, $items))->toBe([
        'Charlie Game ('.today()->addDays(9)->locale('en')->isoFormat('LL').')',
        'Charlie Game ('.today()->addDays(7)->locale('en')->isoFormat('LL').')',
        'Bravo Film ('.today()->addDays(5)->locale('en')->isoFormat('LL').')',
        "Alpha Show S02E01 – Opening ({$date})",
    ])
        ->and(array_diff(array_map(fn (SimpleXMLElement $item): string => (string) $item->guid, $items), $everything))->toBe([])
        ->and((string) $items[3]->link)->toBe(url(route('episode.details', ['ref' => '1399', 'number' => 2, 'episode' => 1], absolute: false)))
        ->and((string) $items[3]->description)->toContain('<p>Season premiere</p>')
        ->and((string) $items[3]->pubDate)->toBe(today()->addDays(3)->toRfc2822String());
});

test('the RSS channel keeps to its window, every release when asked', function () {
    $user = User::factory()->create();
    $offsets = [-(RssFeed::PAST_DAYS + 1), -(RssFeed::PAST_DAYS - 1), RssFeed::FUTURE_DAYS - 1, RssFeed::FUTURE_DAYS + 1];

    foreach ($offsets as $offset) {
        $film = MediaItem::factory()->movie()->create(['title' => "Film {$offset}", 'release_date' => today()->addDays($offset)->toDateString()]);
        app(SyncListMembership::class)->execute($user, $film, [$user->watchlist()->id]);
    }

    $items = rssItems($this->get(route('calendar.rss', ['token' => $user->calendarToken(), 'scope' => 'all']))->assertOk()->getContent());

    expect(array_map(fn (SimpleXMLElement $item): string => explode(' (', (string) $item->title)[0], $items))
        ->toBe(['Film '.$offsets[2], 'Film '.$offsets[1]]);
});

test('the RSS channel speaks the language of the account', function () {
    enableGamesModule();
    $user = User::factory()->create(['locale' => 'pl']);
    feedLibrary($user);

    $xml = $this->get(route('calendar.rss', ['token' => $user->calendarToken()]))->getContent();
    $channel = simplexml_load_string($xml)->channel;
    $episode = rssItems($xml)[3];

    expect((string) $channel->language)->toBe('pl')
        ->and((string) $channel->description)->toBe(__('common.upcoming_releases_from_the_titles_you', [], 'pl'))
        ->and((string) $episode->title)->toBe('Alpha Show S02E01 – Opening ('.today()->addDays(3)->locale('pl')->isoFormat('LL').')')
        ->and((string) $episode->description)->toContain(__('common.season_premiere', [], 'pl'));
});

test('the RSS channel stops at its newest items', function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create(['title' => 'Long Show']);
    $season = MediaItem::factory()->season($show, 1)->create();

    foreach (range(1, RssFeed::MAX_ITEMS + 5) as $number) {
        MediaItem::factory()->episode($season, $number, today()->addDays(intdiv($number, 10))->toDateString())->create();
    }

    app(SyncListMembership::class)->execute($user, $show, [$user->watchlist()->id]);

    $items = rssItems($this->get(route('calendar.rss', ['token' => $user->calendarToken(), 'scope' => 'all']))->getContent());

    $titles = array_map(fn (SimpleXMLElement $item): string => (string) $item->title, $items);

    expect($items)->toHaveCount(RssFeed::MAX_ITEMS)
        ->and(implode("\n", $titles))->toContain('S01E'.(RssFeed::MAX_ITEMS + 5).' ')
        ->not->toContain('S01E01 ');
});

test('the RSS channel 404s on an unknown token', function () {
    $this->get(route('calendar.rss', ['token' => 'deadbeef']))->assertNotFound();
});
