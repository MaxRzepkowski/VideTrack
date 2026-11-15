<?php

use App\Actions\Library\DropAttempt;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\UndoDrop;
use App\Actions\Media\ResolveUpNext;
use App\Catalog\EpisodeBadge;
use App\Catalog\TitleStatus;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Library\ClosesRun;
use App\Library\Data\EpisodeSegment;
use App\Library\Data\UpNextEntry;
use App\Library\EpisodeState;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;

function upNext(User $user): array
{
    return app(ResolveUpNext::class)->execute($user);
}

/** @return list<string> the section's titles, in the order the preference puts them */
function upNextTitles(User $user, string $sort): array
{
    $user->preferences = ['up_next_sort' => $sort];
    $user->save();

    return array_map(fn (UpNextEntry $entry) => $entry->show->title, upNext($user));
}

test('up next points at the first gap, not last watched plus one', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08', '2020-01-15'],
        2 => ['2021-01-01', '2021-01-08'],
        3 => ['2022-01-01', '2022-01-08'],
        4 => ['2023-01-01', '2023-01-08'],
    ]);

    // The classic gap: S01E01 then a jump to S04E01.
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, episodeOf($show, 4, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-02')));

    $entries = upNext($user);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->episode->seasonNumber)->toBe(1)
        ->and($entries[0]->episode->number)->toBe(2)
        ->and($entries[0]->watchedEpisodes)->toBe(2)
        ->and($entries[0]->airedEpisodes)->toBe(9)
        // Second aired episode of the show, seasons counted through.
        ->and($entries[0]->position)->toBe(2)
        // The bar reads S01E01 watched, the jump unwatched, S04E01 watched.
        ->and($entries[0]->segments)->toEqual([
            new EpisodeSegment(EpisodeState::Watched, 1),
            new EpisodeSegment(EpisodeState::Pending, 6),
            new EpisodeSegment(EpisodeState::Watched, 1),
            new EpisodeSegment(EpisodeState::Pending, 1),
        ]);
});

test('specials are skipped and unaired episodes are never suggested', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        0 => ['2019-12-01'],
        1 => ['2020-01-01', '2030-01-01'],
    ]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // S01E01 watched, S00E01 unwatched, S01E02 unaired → nothing to watch.
    expect(upNext($user))->toBeEmpty();
});

test('dropped and on-hold shows leave the section, history intact', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    expect(upNext($user))->toHaveCount(1);

    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    expect(upNext($user))->toBeEmpty()
        ->and(Play::query()->where('outcome', PlayOutcome::Completed->value)->count())->toBe(1);

    app(UndoDrop::class)->execute($user, $show);
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    expect(upNext($user))->toBeEmpty();
});

test('a skipped episode moves the gap to the next one without counting as watched', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08', '2020-01-15']]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(DropAttempt::class)->execute($user, episodeOf($show, 1, 2), WatchedAt::releaseDateOrNow());

    $entries = upNext($user);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->episode->number)->toBe(3)
        ->and($entries[0]->watchedEpisodes)->toBe(1)
        ->and($entries[0]->skippedEpisodes)->toBe(1)
        ->and($entries[0]->airedEpisodes)->toBe(3)
        // The gap moved past the skip, so the card points at the third
        // episode while only one was ever watched.
        ->and($entries[0]->position)->toBe(3)
        ->and($entries[0]->segments)->toEqual([
            new EpisodeSegment(EpisodeState::Watched, 1),
            new EpisodeSegment(EpisodeState::Skipped, 1),
            new EpisodeSegment(EpisodeState::Pending, 1),
        ]);
});

test('a skipped season moves the gap to the next season (anthology)', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
        3 => ['2022-01-01', '2022-01-08'],
    ]);

    app(MarkWatched::class)->execute($user, $show->children()->where('number', 1)->firstOrFail(), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(DropAttempt::class)->execute($user, $show->children()->where('number', 2)->firstOrFail(), WatchedAt::releaseDateOrNow());

    $entries = upNext($user);

    // Season 2 settles the gap without being watched: the counter stays at the
    // two episodes of season 1, and the bar carries the hole.
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->episode->number)->toBe(1)
        ->and($entries[0]->episode->seasonNumber)->toBe(3)
        ->and($entries[0]->watchedEpisodes)->toBe(2)
        ->and($entries[0]->skippedEpisodes)->toBe(2)
        ->and($entries[0]->airedEpisodes)->toBe(6)
        ->and($entries[0]->position)->toBe(5)
        ->and($entries[0]->segments)->toEqual([
            new EpisodeSegment(EpisodeState::Watched, 2),
            new EpisodeSegment(EpisodeState::Skipped, 2),
            new EpisodeSegment(EpisodeState::Pending, 2),
        ]);
});

test('a completed show returns when a new episode airs', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);
    $season = $show->children()->firstOrFail();

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    expect(upNext($user))->toBeEmpty();

    MediaItem::factory()->episode($season, 2, '2026-07-01')->create();
    refreshTitle($show);

    $entries = upNext($user);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->episode->number)->toBe(2);
});

test('entries sort by the most recent play, specials plays included', function () {
    $user = User::factory()->create();
    $first = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $second = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);

    app(MarkWatched::class)->execute($user, episodeOf($first, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, episodeOf($second, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-05')));

    expect(array_column(upNext($user), 'show'))
        ->sequence(
            fn ($show) => $show->ref->toBe($second->provider_ref),
            fn ($show) => $show->ref->toBe($first->provider_ref),
        );
});

test('badges cover premieres and finales of an ended show', function () {
    $user = User::factory()->create();

    // Fresh show watched only via specials → gap at S01E01: series premiere.
    $fresh = makeShowTree([0 => ['2019-12-01'], 1 => ['2020-01-01', '2020-01-08']]);
    $specialEpisode = $fresh->children()->where('number', 0)->firstOrFail()->children()->firstOrFail();
    app(MarkWatched::class)->execute($user, $specialEpisode, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // Gap at S02E01 → season premiere.
    $midway = makeShowTree([1 => ['2020-01-01'], 2 => ['2021-01-01', '2021-01-08']]);
    app(MarkWatched::class)->execute($user, episodeOf($midway, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // Ended show, gap at the very last episode → series finale.
    $ended = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $ended->update(['status' => TitleStatus::Ended]);
    app(MarkWatched::class)->execute($user, episodeOf($ended, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // Running show, gap at the last episode of season 1 of 2 → season finale.
    $running = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2030-01-01']]);
    app(MarkWatched::class)->execute($user, episodeOf($running, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $badges = collect(upNext($user))->mapWithKeys(
        fn (UpNextEntry $entry) => [$entry->show->ref => $entry->badge],
    );

    expect($badges[$fresh->provider_ref])->toBe(EpisodeBadge::SeriesPremiere)
        ->and($badges[$midway->provider_ref])->toBe(EpisodeBadge::SeasonPremiere)
        ->and($badges[$ended->provider_ref])->toBe(EpisodeBadge::SeriesFinale)
        ->and($badges[$running->provider_ref])->toBe(EpisodeBadge::SeasonFinale);
});

test('closes marks the finales that leave nothing more to watch', function () {
    $user = User::factory()->create();

    // Ended show, gap at its very last episode → the run is over.
    $ended = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $ended->update(['status' => TitleStatus::Ended]);
    app(MarkWatched::class)->execute($user, episodeOf($ended, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // Returning show, gap at the finale of its last known season → nothing
    // left until the next season exists.
    $returning = makeShowTree([1 => ['2020-01-01'], 2 => ['2021-01-01', '2021-01-08']]);
    $returning->update(['status' => TitleStatus::ReturningSeries]);
    app(MarkWatched::class)->execute($user, episodeOf($returning, 2, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-02')));
    app(MarkWatched::class)->execute($user, $returning->children()->where('number', 1)->firstOrFail(), WatchedAt::at(CarbonImmutable::parse('2026-01-02')));

    // Same finale, but a later season is already known → not the end.
    $announced = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2030-01-01']]);
    $announced->update(['status' => TitleStatus::ReturningSeries]);
    app(MarkWatched::class)->execute($user, episodeOf($announced, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-03')));

    // Mid-season break: the season itself is unfinished.
    $break = makeShowTree([1 => ['2020-01-01', '2020-01-08', '2020-01-15']]);
    $break->update(['status' => TitleStatus::ReturningSeries]);
    episodeOf($break, 1, 2)->update(['metadata' => ['episodeType' => 'mid_season']]);
    app(MarkWatched::class)->execute($user, episodeOf($break, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-04')));

    // A status the rule does not cover.
    $production = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $production->update(['status' => TitleStatus::InProduction]);
    app(MarkWatched::class)->execute($user, episodeOf($production, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-05')));

    $closes = collect(upNext($user))->mapWithKeys(
        fn (UpNextEntry $entry) => [$entry->show->ref => $entry->closes],
    );

    expect($closes[$ended->provider_ref])->toBe(ClosesRun::Series)
        ->and($closes[$returning->provider_ref])->toBe(ClosesRun::Season)
        ->and($closes[$announced->provider_ref])->toBeNull()
        ->and($closes[$break->provider_ref])->toBeNull()
        ->and($closes[$production->provider_ref])->toBeNull();
});

test('a final season of a single episode still closes the show out', function () {
    $user = User::factory()->create();

    // The badge calls this a season premiere (position wins there); closing
    // out is decided on the facts, so it still counts as the end.
    $show = makeShowTree([1 => ['2020-01-01'], 2 => ['2021-01-01']]);
    $show->update(['status' => TitleStatus::Ended]);
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $entry = upNext($user)[0];

    expect($entry->badge)->toBe(EpisodeBadge::SeasonPremiere)
        ->and($entry->closes)->toBe(ClosesRun::Series);
});

test('movie plays never produce up next entries', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    expect(upNext($user))->toBeEmpty();
});

test('the up next episode uses the season poster with a show fallback', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $show->update(['poster_path' => '/show.jpg']);
    $season = $show->children()->firstOrFail();

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    expect(upNext($user)[0]->poster)->toBe('/show.jpg');

    $season->update(['poster_path' => '/season.jpg']);

    expect(upNext($user)[0]->poster)->toBe('/season.jpg');
});

test('badges prefer the curated episode_type over positional guesses', function () {
    $user = User::factory()->create();

    // A mid-season finale in the middle of the episode list.
    $mid = makeShowTree([1 => ['2020-01-01', '2020-01-08', '2020-01-15']]);
    episodeOf($mid, 1, 2)->update(['metadata' => ['episodeType' => 'mid_season']]);
    app(MarkWatched::class)->execute($user, episodeOf($mid, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // A curated finale that is not the last known episode still badges.
    $curated = makeShowTree([1 => ['2021-01-01', '2021-01-08', '2030-01-01']]);
    episodeOf($curated, 1, 2)->update(['metadata' => ['episodeType' => 'finale']]);
    app(MarkWatched::class)->execute($user, episodeOf($curated, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-02')));

    // The newest known episode of a running season explicitly marked
    // "standard" — no finale badge despite being positionally last.
    $running = makeShowTree([1 => ['2022-01-01', '2022-01-08']]);
    episodeOf($running, 1, 2)->update(['metadata' => ['episodeType' => 'standard']]);
    app(MarkWatched::class)->execute($user, episodeOf($running, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-03')));

    $badges = collect(upNext($user))->mapWithKeys(
        fn (UpNextEntry $entry) => [$entry->show->ref => $entry->badge],
    );

    expect($badges[$mid->provider_ref])->toBe(EpisodeBadge::MidSeasonFinale)
        ->and($badges[$curated->provider_ref])->toBe(EpisodeBadge::SeasonFinale)
        ->and($badges[$running->provider_ref])->toBeNull();
});

test('the section order follows the up next sort preference', function () {
    $user = User::factory()->create();

    // Watched most recently, one 60-minute episode waiting, aired 2024.
    $zulu = makeShowTree([1 => ['2019-01-01', '2024-01-01']]);
    $zulu->update(['title' => 'Zulu']);
    episodeOf($zulu, 1, 2)->update(['runtime' => 60]);
    app(MarkWatched::class)->execute($user, episodeOf($zulu, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-03')));

    // The shortest run left (2 × 20 min) on the oldest waiting episode.
    $mike = makeShowTree([1 => ['2019-01-01', '2021-06-01', '2021-06-08']]);
    $mike->update(['title' => 'Mike']);
    episodeOf($mike, 1, 2)->update(['runtime' => 20]);
    episodeOf($mike, 1, 3)->update(['runtime' => 20]);
    app(MarkWatched::class)->execute($user, episodeOf($mike, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-02')));

    // Watched longest ago, the longest run left.
    $alpha = makeShowTree([1 => ['2019-01-01', '2023-01-01']]);
    $alpha->update(['title' => 'Alpha']);
    episodeOf($alpha, 1, 2)->update(['runtime' => 90]);
    app(MarkWatched::class)->execute($user, episodeOf($alpha, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    expect(upNextTitles($user, 'activity'))->toBe(['Zulu', 'Mike', 'Alpha'])
        // Mike has two 20-minute episodes left, Zulu 60 minutes, Alpha 90.
        ->and(upNextTitles($user, 'remaining'))->toBe(['Mike', 'Zulu', 'Alpha'])
        ->and(upNextTitles($user, 'released'))->toBe(['Zulu', 'Alpha', 'Mike'])
        ->and(upNextTitles($user, 'title'))->toBe(['Alpha', 'Mike', 'Zulu'])
        // An unknown value is not an error — the default order stands.
        ->and(upNextTitles($user, 'nonsense'))->toBe(['Zulu', 'Mike', 'Alpha']);
});

test('an episode with no runtime costs what the show declares an episode costs', function () {
    $user = User::factory()->create();

    $short = makeShowTree([1 => ['2019-01-01', '2020-01-01']]);
    $short->update(['title' => 'Short']);
    episodeOf($short, 1, 2)->update(['runtime' => 40]);
    app(MarkWatched::class)->execute($user, episodeOf($short, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // Hour-long show whose waiting episode has no runtime on record: priced at
    // the length the show itself declares (TMDB's `episode_run_time`) it lands
    // behind the 40-minute one, priced at zero it would lead the section.
    $long = makeShowTree([1 => ['2019-01-01', '2020-01-01']]);
    $long->update(['title' => 'Long', 'runtime' => 100]);
    episodeOf($long, 1, 2)->update(['runtime' => null]);
    app(MarkWatched::class)->execute($user, episodeOf($long, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-02')));

    expect(upNextTitles($user, 'remaining'))->toBe(['Short', 'Long']);
});

test('a show declaring no episode length prices its runtime-less episodes at nothing', function () {
    $user = User::factory()->create();

    $show = makeShowTree([1 => ['2019-01-01', '2020-01-01']]);
    $show->update(['runtime' => null]);
    episodeOf($show, 1, 2)->update(['runtime' => null]);
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // Nothing anywhere says how long an episode is, so the card compares by
    // episode count alone rather than inventing minutes.
    $entry = app(ResolveUpNext::class)->execute($user)[0];

    expect($entry->remainingMinutes)->toBe(0)
        ->and($entry->remainingEpisodes)->toBe(1);
});
