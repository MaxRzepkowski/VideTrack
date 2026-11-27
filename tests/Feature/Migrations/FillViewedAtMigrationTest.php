<?php

use App\Models\MediaItem;
use App\Models\RecentView;
use Illuminate\Database\Migrations\Migration;

/*
 * A title's last opening, filled from the users' recently viewed: the newest
 * of their views of the title, on its root alone.
 */

function fillViewedAt(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_27_000003_fill_viewed_at_from_recent_views.php');
    $migration->up();
}

test('a root takes the newest view of it, from whichever user', function () {
    $show = MediaItem::factory()->show()->create();
    RecentView::factory()->of($show)->create(['viewed_at' => '2026-09-01 10:00:00']);
    RecentView::factory()->of($show)->create(['viewed_at' => '2026-10-01 10:00:00']);

    fillViewedAt();

    expect($show->refresh()->viewed_at?->toDateTimeString())->toBe('2026-10-01 10:00:00');
});

test('a title nobody viewed, and a season, stay without an opening', function () {
    $show = MediaItem::factory()->show()->create();
    $season = MediaItem::factory()->season($show, 1)->create();
    $unseen = MediaItem::factory()->movie()->create();
    RecentView::factory()->of($season)->create();

    fillViewedAt();

    expect($season->refresh()->viewed_at)->toBeNull()
        ->and($unseen->refresh()->viewed_at)->toBeNull()
        ->and($show->refresh()->viewed_at)->toBeNull();
});
