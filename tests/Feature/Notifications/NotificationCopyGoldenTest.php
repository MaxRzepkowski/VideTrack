<?php

use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\RelatedRelease;
use App\Catalog\DatePrecision;
use App\Catalog\EpisodeBadge;
use App\Catalog\GameKind;
use App\Enums\ReleaseKind;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Notifications\Events\CollectionParts;
use App\Notifications\Events\CreatorNewTitle;
use App\Notifications\Events\DateChanged;
use App\Notifications\Events\EpisodesReleased;
use App\Notifications\Events\HomeRelease;
use App\Notifications\Events\RelatedAnnounced;
use App\Notifications\Events\Released;
use App\Notifications\Events\SeasonAnnounced;
use App\Notifications\Events\ShowStatus;
use App\Notifications\Events\TitlesRemoved;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use App\Notifications\NotificationCopy;
use App\Tracking\Releases\ReleaseEntry;
use Illuminate\Support\Facades\URL;

/*
 * Everything a notification says and where it leads, for every kind of event
 * and its variants, in both languages: what the bell, the pages, the digest
 * and a channel's message are made of.
 */

test('every kind of event says the same, wherever it is shown', function () {
    // A channel's link carries the instance's address: a neutral one, whatever the local environment says.
    config(['app.url' => 'http://localhost']);
    URL::forceRootUrl('http://localhost');
    $card = fn (array $overrides = []): array => [...['type' => 'movie', 'ref' => '603', 'title' => 'Dune', 'posterPath' => '/dune.jpg', 'backdropPath' => '/dune-wide.jpg', 'extra' => []], ...$overrides];
    $episode = fn (int $number, array $extra = []): array => $card(['type' => 'episode', 'ref' => "1399:2:{$number}", 'title' => "Chapter {$number}", 'number' => $number, 'posterPath' => null, 'releaseTime' => '2026-10-10T01:00:00+00:00', 'extra' => ['seasonNumber' => 2, 'showBackdrop' => '/show.jpg', ...$extra]]);
    $titles = fn (int $count): array => array_map(fn (int $i): array => $card(['ref' => (string) (700 + $i), 'title' => "Title {$i}"]), range(1, $count));
    $dlc = $card(['type' => 'game', 'ref' => '5000', 'title' => 'The Master Trials', 'extra' => ['gameType' => 'dlc', 'relatedTo' => 'Breath of the Wild', 'platform' => 'Switch, PC']]);

    $events = [
        'unknown kind' => ['event' => 'from_a_newer_server', 'media' => $card()],
        'an episode' => ['event' => 'episode_aired', 'media' => $episode(5), 'showTitle' => 'Severance', 'season' => 2, 'date' => '2026-10-10'],
        'episodes, the finale among them' => ['event' => 'episode_aired', 'media' => $episode(8), 'showTitle' => 'Severance', 'items' => [$episode(8), $episode(9, ['episodeBadge' => 'season_finale'])]],
        'a season opening with several' => ['event' => 'season_premiere', 'media' => $episode(1), 'showTitle' => 'Severance', 'items' => [$episode(1), $episode(2), $episode(3)]],
        'a premiere' => ['event' => 'premiere', 'media' => $card(), 'date' => '2026-10-10'],
        'a game premiere on platforms' => ['event' => 'premiere', 'media' => $card(['type' => 'game', 'ref' => '1942', 'title' => 'The Witcher 3', 'extra' => ['platform' => 'PC, PS5']]), 'date' => '2026-10-10'],
        'a dlc premiere' => ['event' => 'premiere', 'media' => $dlc, 'date' => '2026-10-10'],
        'a home release' => ['event' => 'home_release', 'media' => $card(), 'date' => '2026-10-10'],
        'a moved date' => ['event' => 'date_changed', 'media' => $card(), 'date' => '2027-03-12', 'oldDate' => '2026-11-20'],
        'a date sharpened from a year' => ['event' => 'date_changed', 'media' => $card(['extra' => ['releasePrecision' => 'day']]), 'date' => '2028-03-12', 'oldDate' => '2028-12-31', 'oldDatePrecision' => 'year'],
        'a date first announced, a quarter' => ['event' => 'date_changed', 'media' => $card(['extra' => ['releasePrecision' => 'quarter']]), 'date' => '2027-03-31'],
        'a dlc date moved' => ['event' => 'date_changed', 'media' => $dlc, 'date' => '2027-03-12', 'oldDate' => '2027-01-15'],
        'a status change' => ['event' => 'show_status', 'media' => $card(['type' => 'show', 'ref' => '1399', 'title' => 'Severance']), 'status' => 'ended', 'oldStatus' => 'returning_series'],
        'a status of no known kind' => ['event' => 'show_status', 'media' => $card(['type' => 'show']), 'status' => 'something_new', 'oldStatus' => 'ended'],
        'a season announced' => ['event' => 'season_announced', 'media' => $card(['type' => 'show', 'ref' => '1399', 'title' => 'Severance']), 'season' => 3],
        'a person\'s new title' => ['event' => 'person_new_title', 'media' => $card(), 'date' => '2027-03-12', 'personName' => 'Denis Villeneuve'],
        'a company\'s new title' => ['event' => 'company_new_title', 'media' => $card(), 'personName' => null, 'companyName' => 'Legendary'],
        'a collection\'s new part' => ['event' => 'collection_new_item', 'media' => $card(), 'date' => '2027-03-12', 'collectionTitle' => 'Dune Collection', 'collectionProvider' => 'tmdb', 'collectionRef' => '726871'],
        'a collection\'s new parts' => ['event' => 'collection_new_item', 'media' => $titles(5)[0], 'items' => $titles(5), 'collectionTitle' => 'Dune Collection', 'collectionProvider' => 'tmdb', 'collectionRef' => '726871'],
        'a collection without an address' => ['event' => 'collection_new_item', 'media' => $titles(2)[0], 'items' => $titles(2), 'collectionTitle' => 'Old List'],
        'one title removed' => ['event' => 'titles_removed', 'media' => $card(), 'items' => [$card()]],
        'titles removed' => ['event' => 'titles_removed', 'media' => $titles(4)[0], 'items' => $titles(4)],
        'a dlc announced' => ['event' => 'related_announced', 'media' => $dlc, 'date' => '2027-03-12'],
        'a remaster announced for a year' => ['event' => 'related_announced', 'media' => $card(['type' => 'game', 'ref' => '5003', 'title' => 'HD', 'extra' => ['gameType' => 'remaster', 'relatedTo' => 'Breath of the Wild', 'releasePrecision' => 'year']]), 'date' => '2027-12-31'],
    ];

    $copy = app(NotificationCopy::class);
    $said = [];

    foreach ($events as $name => $data) {
        $event = MediaEventPayload::fromStored(['key' => "key-of-{$name}", ...$data]);

        foreach (['en', 'pl'] as $locale) {
            $said[$name][$locale] = [
                'title' => $copy->title($event, $locale),
                'detail' => $copy->detail($event, $locale),
                'plain' => $copy->plainDetail($event, $locale),
                'timed' => $copy->timedDetail($event, $locale, 'Europe/Warsaw'),
                'digest' => $copy->digestParts($event, $locale, 'Europe/Warsaw'),
            ];
        }

        $said[$name]['href'] = $copy->href('notification-1', $event);
        $said[$name]['url'] = $copy->url($event);
        $said[$name]['image'] = [$copy->imagePath($event), $copy->imageUrl($event)];
        $said[$name]['color'] = $copy->color($event);
        $said[$name]['stored'] = $event->toStored();
    }

    expectGolden('notification-copy', $said);
});

test('every factory keys its event and stores it the same', function () {
    $film = MediaItem::factory()->movie()->create(['provider_ref' => '603', 'title' => 'Dune', 'release_date' => '2027-03-12']);
    $sequel = MediaItem::factory()->movie()->create(['provider_ref' => '604', 'title' => 'Dune Two', 'release_date' => '2028-12-31', 'release_date_precision' => DatePrecision::Year]);
    $show = MediaItem::factory()->show()->create(['provider_ref' => '1399', 'title' => 'Severance', 'release_date' => '2022-02-18']);
    $game = MediaItem::factory()->game()->create(['provider_ref' => '7346', 'title' => 'Breath of the Wild', 'release_date' => '2017-03-03']);
    $saga = MediaList::factory()->collection('tmdb', '726871')->create(['title' => 'Dune Collection']);
    $dlc = new RelatedRelease('5000', 'The Master Trials', GameKind::Dlc, null, '2027-12-31', DatePrecision::Year);
    $filmEntry = fn (?ReleaseKind $kind = null): ReleaseEntry => new ReleaseEntry(new MediaSummary(type: 'movie', ref: '603', provider: 'tmdb', title: 'Dune', releaseKind: $kind), rootId: $film->id, rootType: 'movie');
    $episodeEntry = fn (int $number, ?EpisodeBadge $badge = null): ReleaseEntry => new ReleaseEntry(
        new MediaSummary(type: 'episode', ref: "1399:2:{$number}", provider: 'tmdb', title: "Chapter {$number}", number: $number, seasonNumber: 2, episodeBadge: $badge),
        rootId: $show->id,
        rootType: 'show',
        showTitle: 'Severance',
    );

    $events = [
        'premiere' => Released::of($filmEntry(), '2026-10-10'),
        'premiere and home release on one day' => Released::of($filmEntry(ReleaseKind::Both), '2026-10-10'),
        'home release' => Released::of($filmEntry(ReleaseKind::Home), '2026-10-10'),
        'home release beside a premiere' => HomeRelease::of($filmEntry(ReleaseKind::Both), '2026-10-10'),
        'episode' => Released::of($episodeEntry(5), '2026-10-10'),
        'season premiere' => Released::of($episodeEntry(1, EpisodeBadge::SeasonPremiere), '2026-10-10'),
        'episodes' => EpisodesReleased::several([$episodeEntry(5), $episodeEntry(6)], '2026-10-10'),
        'episodes opening a season' => EpisodesReleased::several([$episodeEntry(1, EpisodeBadge::SeasonPremiere), $episodeEntry(2)], '2026-10-10'),
        'date moved' => DateChanged::of($film, '2026-11-20', DatePrecision::Day),
        'date moved to a year' => DateChanged::of($sequel, '2028-03-01', DatePrecision::Month),
        'date first announced' => DateChanged::of($film, null, DatePrecision::Day),
        'dlc date moved' => DateChanged::ofRelated($game, $dlc, '2027-06-30', DatePrecision::Quarter),
        'dlc announced' => RelatedAnnounced::of($game, $dlc),
        'status' => ShowStatus::of($show, 'Returning Series', 'Ended'),
        'season announced' => SeasonAnnounced::of($show, 3),
        'person title' => CreatorNewTitle::of(MediaEventType::PersonNewTitle, $film, 'Denis Villeneuve'),
        'company title' => CreatorNewTitle::of(MediaEventType::CompanyNewTitle, $sequel, 'Legendary'),
        'collection part' => CollectionParts::of($saga, [$film]),
        'collection parts' => CollectionParts::of($saga, [$sequel, $film]),
        'titles removed' => TitlesRemoved::of([$film, $sequel]),
    ];

    // The cards are the summaries' business; the event's own fields and which cards it holds are its factory's.
    $card = fn (mixed $card): array => is_array($card) ? array_intersect_key($card, array_flip(['type', 'ref', 'title', 'releaseDate', 'extra'])) : [];

    expectGolden('media-event-factories', array_map(function (MediaEventPayload $event) use ($card): array {
        $stored = $event->toStored();
        $stored['media'] = $card($stored['media']);

        if (isset($stored['items']) && is_array($stored['items'])) {
            $stored['items'] = array_map($card, $stored['items']);
        }

        return $stored;
    }, $events));
});
