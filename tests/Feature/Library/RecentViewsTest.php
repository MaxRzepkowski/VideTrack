<?php

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaItemSummaries;
use App\Library\RecentViews;
use App\Models\MediaItem;
use App\Models\RecentView;
use App\Models\User;
use App\Tasks\Maintenance\Checks\ExpiredDataCleanup;
use App\Tasks\Maintenance\MaintenanceChecks;

function viewed(string $type, string $ref, string $title): MediaSummary
{
    return new MediaSummary(type: $type, ref: $ref, provider: 'tmdb', title: $title, posterPath: "/{$ref}.jpg");
}

test('recording a view upserts by (type, ref) and bumps the timestamp', function () {
    $user = User::factory()->create();
    $action = app(RecentViews::class);

    $action->record($user, viewed('movie', '603', 'The Matrix'));
    $this->travel(1)->hours();
    $action->record($user, viewed('movie', '603', 'The Matrix (renamed)'));

    $rows = RecentView::query()->where('user_id', $user->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->title)->toBe('The Matrix (renamed)');
});

test('the nightly clean-up trims each history to the newest entries', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $action = app(RecentViews::class);

    foreach (range(1, 30) as $i) {
        $this->travel(1)->minutes();
        $action->record($user, viewed('movie', (string) $i, "Movie {$i}"));
    }
    $action->record($other, viewed('movie', '1', 'Movie 1'));

    // A page view only records; the trim waits for the clean-up.
    expect(RecentView::query()->where('user_id', $user->id)->count())->toBe(30);

    app(MaintenanceChecks::class)->perform('run-of-alex', [ExpiredDataCleanup::CODE]);

    $refs = RecentView::query()->where('user_id', $user->id)->pluck('provider_ref');
    expect(RecentView::query()->where('user_id', $other->id)->count())->toBe(1);

    expect($refs)->toHaveCount(24)
        ->and($refs)->toContain('30')
        ->and($refs)->not->toContain('6');
});

test('recent excludes the current item and is scoped per user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $action = app(RecentViews::class);

    $action->record($user, viewed('movie', '1', 'A'));
    $action->record($user, viewed('show', '9', 'B'));
    $action->record($user, viewed('movie', '2', 'C'));
    $action->record($other, viewed('movie', '7', 'Other'));

    $recent = $action->recent($user, except: viewed('movie', '2', 'C'));

    expect(collect($recent)->pluck('ref')->all())
        ->toContain('1')
        ->toContain('9')
        ->not->toContain('2')
        ->not->toContain('7');
});

test('opening a title marks it opened, beside the user\'s own recently viewed, and leaves the row unchanged', function () {
    $movie = MediaItem::factory()->movie()->create(['updated_at' => now()->subDay()]);

    $this->travel(1)->minutes();
    app(RecentViews::class)->record(User::factory()->create(), MediaItemSummaries::summary($movie));

    expect($movie->refresh()->viewed_at?->eq(now()))->toBeTrue()
        ->and($movie->updated_at->lt(now()->subHour()))->toBeTrue();
});
