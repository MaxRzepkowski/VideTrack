<?php

use App\Notifications\MediaEventPayload;
use App\Notifications\NotificationCopy;

/*
 * What a notification says, in both languages: the headline and the facts
 * under it, as the bell, the notifications page and Discord show them.
 */

/** @return array<string, mixed> */
function notifiedMedia(array $overrides = []): array
{
    return [...['type' => 'movie', 'ref' => '1', 'title' => 'Dune', 'posterPath' => null, 'backdropPath' => null, 'extra' => []], ...$overrides];
}

/** @return list<array<string, mixed>> */
function notifiedTitles(int $count): array
{
    return array_map(fn (int $i): array => notifiedMedia(['ref' => (string) $i, 'title' => "T{$i}"]), range(0, $count - 1));
}

/**
 * @param  array<string, mixed>  $data
 * @return array{string, list<string>}
 */
function rendered(array $data, string $locale): array
{
    $copy = app(NotificationCopy::class);
    $event = MediaEventPayload::fromStored(['media' => notifiedMedia(), ...$data]);

    return [$copy->title($event, $locale), $copy->detail($event, $locale)];
}

$episode = notifiedMedia(['type' => 'episode', 'ref' => '10:2:5', 'title' => 'The Return', 'number' => 5, 'extra' => ['seasonNumber' => 2]]);

test('every event in both languages', function (array $data, array $en, array $pl) {
    expect(rendered($data, 'en'))->toBe($en)
        ->and(rendered($data, 'pl'))->toBe($pl);
})->with([
    'an aired episode names its show and codes the episode' => [
        ['event' => 'episode_aired', 'media' => $episode, 'showTitle' => 'Severance'],
        ['New episode of Severance', ['S02E05', 'The Return']],
        ['Nowy odcinek Severance', ['S02E05', 'The Return']],
    ],
    'an episode without a title is its code alone, the season given' => [
        ['event' => 'episode_aired', 'media' => [...$episode, 'title' => ''], 'season' => 3, 'showTitle' => 'Severance'],
        ['New episode of Severance', ['S03E05']],
        ['Nowy odcinek Severance', ['S03E05']],
    ],
    'a season premiere' => [
        ['event' => 'season_premiere', 'media' => $episode],
        ['Season premiere of The Return', ['S02E05', 'The Return']],
        ['Premiera sezonu The Return', ['S02E05', 'The Return']],
    ],
    "a show's episodes out together are counted in the headline alone" => [
        ['event' => 'episode_aired', 'media' => $episode, 'showTitle' => 'Severance', 'items' => [$episode, [...$episode, 'number' => 6], [...$episode, 'number' => 7]]],
        ['3 new episodes of Severance', []],
        ['3 nowe odcinki Severance', []],
    ],
    'several episodes ending a season name the finale' => [
        ['event' => 'episode_aired', 'media' => $episode, 'showTitle' => 'Severance', 'items' => [$episode, [...$episode, 'number' => 6, 'extra' => ['seasonNumber' => 2, 'episodeBadge' => 'season_finale']]]],
        ['2 new episodes of Severance', ['Season finale']],
        ['2 nowe odcinki Severance', ['Finał sezonu']],
    ],
    'a season opening with several episodes counts them, a finale among them left to the page' => [
        ['event' => 'season_premiere', 'media' => $episode, 'showTitle' => 'Severance', 'items' => [
            [...$episode, 'number' => 1, 'extra' => ['seasonNumber' => 2, 'episodeBadge' => 'season_premiere']],
            [...$episode, 'number' => 2],
            [...$episode, 'number' => 3, 'extra' => ['seasonNumber' => 2, 'episodeBadge' => 'season_finale']],
        ]],
        ['Season premiere of Severance', ['3 episodes']],
        ['Premiera sezonu Severance', ['3 odcinki']],
    ],
    'a premiere adds its platform' => [
        ['event' => 'premiere', 'media' => notifiedMedia(['extra' => ['platform' => 'PS5']])],
        ['Out today: Dune', ['PS5']],
        ['Dziś premiera: Dune', ['PS5']],
    ],
    'a premiere without one adds nothing' => [
        ['event' => 'premiere'],
        ['Out today: Dune', []],
        ['Dziś premiera: Dune', []],
    ],
    'a home release adds nothing' => [
        ['event' => 'home_release'],
        ['Now available outside cinemas: Dune', []],
        ['Dostępny poza kinem: Dune', []],
    ],
    'a moved date is one fact' => [
        ['event' => 'date_changed', 'date' => '2026-11-02', 'oldDate' => '2026-10-20'],
        ['New release date for Dune', ['October 20, 2026 → November 2, 2026']],
        ['Nowa data premiery Dune', ['20 października 2026 → 2 listopada 2026']],
    ],
    'a year sharpened to its day says both as far as known' => [
        ['event' => 'date_changed', 'date' => '2028-03-12', 'oldDate' => '2028-12-31', 'oldDatePrecision' => 'year'],
        ['New release date for Dune', ['2028 → March 12, 2028']],
        ['Nowa data premiery Dune', ['2028 → 12 marca 2028']],
    ],
    'a date pushed back to a quarter or a month names only that' => [
        ['event' => 'date_changed', 'media' => notifiedMedia(['extra' => ['releasePrecision' => 'quarter']]), 'date' => '2028-06-30', 'oldDate' => '2028-03-01', 'oldDatePrecision' => 'month'],
        ['New release date for Dune', ['March 2028 → Q2 2028']],
        ['Nowa data premiery Dune', ['marzec 2028 → 2. kwartał 2028']],
    ],
    'a first announced date' => [
        ['event' => 'date_changed', 'date' => '2026-11-02'],
        ['New release date for Dune', ['November 2, 2026']],
        ['Nowa data premiery Dune', ['2 listopada 2026']],
    ],
    'a status change reads in the language of the message' => [
        ['event' => 'show_status', 'status' => 'ended', 'oldStatus' => 'returning_series'],
        ['Dune changed status', ['Returning series → Ended']],
        ['Zmiana statusu: Dune', ['Powracający serial → Zakończony']],
    ],
    'a status change without a status adds nothing' => [
        ['event' => 'show_status'],
        ['Dune changed status', []],
        ['Zmiana statusu: Dune', []],
    ],
    'an announced season' => [
        ['event' => 'season_announced', 'season' => 4],
        ['New season of Dune announced', ['Season 4']],
        ['Zapowiedziano nowy sezon Dune', ['Sezon 4']],
    ],
    'a followed person names the title and its date' => [
        ['event' => 'person_new_title', 'personName' => 'Denis', 'date' => '2027-01-05'],
        ['New title with Denis', ['Dune', 'January 5, 2027']],
        ['Nowy tytuł z Denis', ['Dune', '5 stycznia 2027']],
    ],
    'a followed company' => [
        ['event' => 'company_new_title', 'companyName' => 'A24'],
        ['New title from A24', ['Dune']],
        ['Nowy tytuł od A24', ['Dune']],
    ],
    'a collection names itself for one new part' => [
        ['event' => 'collection_new_item', 'collectionTitle' => 'Dune Collection', 'collectionProvider' => 'tmdb', 'collectionRef' => '9'],
        ['New part of Dune Collection', ['Dune']],
        ['Nowa część serii Dune Collection', ['Dune']],
    ],
    'a collection counts several and previews a few' => [
        ['event' => 'collection_new_item', 'collectionTitle' => 'Dune', 'collectionProvider' => 'tmdb', 'collectionRef' => '9', 'items' => notifiedTitles(6)],
        ['6 new parts of Dune', ['T0', 'T1', 'T2', '3 more']],
        ['6 nowych części serii Dune', ['T0', 'T1', 'T2', 'i jeszcze 3']],
    ],
    'one removed title' => [
        ['event' => 'titles_removed', 'items' => notifiedTitles(1)],
        ['1 title from your library is no longer available at its data source', ['T0']],
        ['1 tytuł z Twojej biblioteki zniknął ze swojego źródła danych', ['T0']],
    ],
    'several removed titles' => [
        ['event' => 'titles_removed', 'items' => notifiedTitles(5)],
        ['5 titles from your library are no longer available at their data source', ['T0', 'T1', 'T2', '2 more']],
        ['5 tytułów z Twojej biblioteki zniknęło ze swoich źródeł danych', ['T0', 'T1', 'T2', 'i jeszcze 2']],
    ],
    'an event this version does not know' => [
        ['event' => 'renamed'],
        ['Dune', []],
        ['Dune', []],
    ],
]);

test("a channel message links a title to its page and paints it in its type colour, a part of a show in the show's", function () {
    $copy = app(NotificationCopy::class);
    $episode = MediaEventPayload::fromStored(['event' => 'episode_aired', 'media' => notifiedMedia(['type' => 'episode', 'ref' => '10:2:5', 'title' => 'The Return', 'number' => 5, 'extra' => ['seasonNumber' => 2]])]);
    $season = MediaEventPayload::fromStored(['event' => 'season_announced', 'media' => notifiedMedia(['type' => 'season', 'ref' => '10:2'])]);
    $film = MediaEventPayload::fromStored(['event' => 'premiere', 'media' => notifiedMedia()]);
    $unknown = MediaEventPayload::fromStored(['event' => 'premiere', 'media' => notifiedMedia(['type' => 'podcast'])]);

    expect($copy->url($episode))->toBe(route('episode.details', ['ref' => '10', 'number' => '2', 'episode' => '5']))
        ->and($copy->url($season))->toBe(route('season.details', ['ref' => '10', 'number' => '2']))
        ->and($copy->url($film))->toBe(route('movie.details', ['ref' => '1']))
        ->and($copy->url($unknown))->toBe(route('search'))
        ->and($copy->color($episode))->toBe(0x8136FF)
        ->and($copy->color($film))->toBe(0x2E90FA)
        ->and($copy->color($unknown))->toBe(0x7337FF)
        ->and($copy->plainDetail($episode, 'en'))->toBe('S02E05 – The Return');
});

test("the digest counts a show's episodes out together and links the first of them", function () {
    $copy = app(NotificationCopy::class);
    $episode = notifiedMedia(['type' => 'episode', 'ref' => '10:2:5', 'title' => 'The Return', 'number' => 5, 'extra' => ['seasonNumber' => 2]]);
    $event = MediaEventPayload::fromStored(['event' => 'episode_aired', 'media' => $episode, 'showTitle' => 'Severance', 'items' => [$episode, [...$episode, 'number' => 6]]]);

    expect($copy->digestParts($event, 'en', 'UTC'))->toBe(['subject' => 'Severance', 'label' => '2 new episodes', 'meta' => null])
        ->and($copy->digestParts($event, 'pl', 'UTC')['label'])->toBe('2 nowe odcinki')
        ->and($copy->url($event))->toBe(route('episode.details', ['ref' => '10', 'number' => '2', 'episode' => '5']));
});

test('a status change reads in the language of the message, not the provider', function () {
    $event = MediaEventPayload::fromStored(['event' => 'show_status', 'status' => 'ended', 'oldStatus' => 'returning_series', 'media' => ['type' => 'show', 'ref' => '1', 'title' => 'Fargo']]);

    expect(app(NotificationCopy::class)->plainDetail($event, 'en'))->toBe('Returning series → Ended')
        ->and(app(NotificationCopy::class)->plainDetail($event, 'pl'))->toBe('Powracający serial → Zakończony');
});

test('parts a collection gained at once are counted, previewed and lead to the collection', function () {
    $parts = array_map(fn (int $n): array => ['type' => 'movie', 'ref' => (string) $n, 'title' => "Part {$n}"], range(1, 6));
    $event = MediaEventPayload::fromStored(['event' => 'collection_new_item', 'collectionTitle' => 'Dune', 'collectionProvider' => 'tmdb', 'collectionRef' => '9', 'media' => $parts[0], 'items' => $parts]);
    $copy = app(NotificationCopy::class);

    expect($copy->title($event, 'en'))->toBe('6 new parts of Dune')
        ->and($copy->title($event, 'pl'))->toBe('6 nowych części serii Dune')
        ->and($copy->plainDetail($event, 'en'))->toBe('Part 1 – Part 2 – Part 3 – 3 more')
        ->and($copy->url($event))->toBe(route('collections.show', ['provider' => 'tmdb', 'ref' => '9']));
});

test('parts without a collection address lead to the first of them', function () {
    $parts = array_map(fn (int $n): array => ['type' => 'movie', 'ref' => (string) $n, 'title' => "Part {$n}"], range(1, 2));
    $event = MediaEventPayload::fromStored(['event' => 'collection_new_item', 'collectionTitle' => 'Dune', 'collectionId' => 9, 'media' => $parts[0], 'items' => $parts]);

    expect(app(NotificationCopy::class)->url($event))->toBe(route('movie.details', ['ref' => '1']));
});
