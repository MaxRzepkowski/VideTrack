<?php

use App\Actions\Library\MarkWatched;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * "Watch the first / next episode" on a show or a season: the play lands on one
 * episode, chosen server-side. The client sends the container it clicked — which
 * leaf that is stays the hierarchy's answer (specials skipped, unaired episodes
 * skipped, watched ones behind it), so these tests guard the choice rather than
 * the button that makes it.
 */
function startNextEpisode(User $user, MediaItem $container): TestResponse
{
    return test()->actingAs($user)->postJson(route('ui.media.plays.store', ['type' => $container->type, 'ref' => $container->provider_ref]), ['watched_at' => 'now', 'next_episode' => true]);
}

test('starting a show marks its first aired episode, not the whole run', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2020-02-01']]);

    startNextEpisode($user, $show)->assertCreated();

    $first = MediaItem::query()->where('type', 'episode')->orderBy('id')->first();

    expect(Play::count())->toBe(1)
        ->and(Play::first()->media_item_id)->toBe($first->id);
});

test('the specials season is not where a show starts', function () {
    $user = User::factory()->create();
    $show = makeShowTree([0 => ['2019-12-24'], 1 => ['2020-01-01']]);

    startNextEpisode($user, $show);

    $played = MediaItem::query()->find(Play::first()->media_item_id);

    expect($played->parent->number)->toBe(1);
});

test('an episode that has not aired is not where a show starts', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2030-01-01', null]]);

    startNextEpisode($user, $show)->assertJsonValidationErrors('next_episode');

    expect(Play::count())->toBe(0);
});

test('starting a season marks the first episode of that season alone', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01'], 2 => ['2020-02-01', '2020-02-08']]);
    $second = $show->children()->where('number', 2)->first();

    startNextEpisode($user, $second);

    $played = MediaItem::query()->find(Play::first()->media_item_id);

    expect(Play::count())->toBe(1)
        ->and($played->parent_id)->toBe($second->id)
        ->and($played->number)->toBe(1);
});

test('a show under way carries on from the first gap, not from the top', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08', '2020-01-15']]);
    $episodes = $show->children()->first()->children()->orderBy('number')->get();

    app(MarkWatched::class)->execute($user, $episodes[0], WatchedAt::at(CarbonImmutable::now()));

    startNextEpisode($user, $show);

    expect(Play::count())->toBe(2)
        ->and(Play::orderByDesc('id')->first()->media_item_id)->toBe($episodes[1]->id);
});

test('a show with every aired episode watched has nothing to carry on with', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2030-01-01']]);
    $aired = $show->children()->first()->children()->orderBy('number')->first();

    app(MarkWatched::class)->execute($user, $aired, WatchedAt::at(CarbonImmutable::now()));

    startNextEpisode($user, $show)->assertJsonValidationErrors('next_episode');

    expect(Play::count())->toBe(1);
});
