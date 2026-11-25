<?php

use App\Library\ListPositions;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('a position is appended after the last one, with the time it was added', function () {
    $list = MediaList::factory()->create();
    $positions = app(ListPositions::class);

    $positions->append($list->id, ['media_item_id' => MediaItem::factory()->movie()->create()->id]);
    $positions->append($list->id, [
        'media_item_id' => MediaItem::factory()->movie()->create()->id,
        'created_at' => CarbonImmutable::parse('2024-03-01'),
        'updated_at' => CarbonImmutable::parse('2024-03-01'),
    ]);

    $items = ListItem::query()->where('list_id', $list->id)->orderBy('position')->get();

    expect($items->pluck('position')->all())->toBe([1, 2])
        ->and($items[0]->created_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($items[1]->created_at?->toDateString())->toBe('2024-03-01');
});

test('a manual order puts the named ids first and keeps the rest as they were', function () {
    $rows = collect([1 => 'a', 2 => 'b', 3 => 'c', 4 => 'd'])
        ->map(fn (string $title, int $id) => (new MediaList)->forceFill(['id' => $id, 'title' => $title]));

    $ordered = app(ListPositions::class)->ordered($rows, [3, 99, 1]);

    expect(array_map(fn (MediaList $list) => $list->title, $ordered))->toBe(['c', 'a', 'b', 'd']);
});

test('a new order is written in one statement, and only for the rows that move', function () {
    $user = User::factory()->create();
    $lists = collect(range(1, 4))->map(fn (int $position) => MediaList::factory()->for($user)->create(['position' => $position]))->keyBy('id');
    [$first, $second, $third, $fourth] = $lists->values()->all();

    $queries = queriesRunBy(fn () => app(ListPositions::class)->save($lists, [$first->id => 1, $second->id => 3, $third->id => 2, $fourth->id => 4]));

    expect($queries)->toHaveCount(1)
        ->and(MediaList::query()->whereNull('system_key')->orderBy('position')->pluck('id')->all())->toBe([$first->id, $third->id, $second->id, $fourth->id]);
});

test('a title on the list page carries the ids its spreadsheet export keys it by, a person none', function () {
    $user = User::factory()->create();
    $list = MediaList::factory()->create(['user_id' => $user->id]);
    $film = MediaItem::factory()->movie()->create(['metadata' => ['imdbId' => 'tt0133093']]);
    ListItem::factory()->create(['list_id' => $list->id, 'media_item_id' => $film->id, 'position' => 1]);
    ListItem::factory()->create(['list_id' => $list->id, 'media_item_id' => null, 'person_id' => Person::factory()->create()->id, 'position' => 2]);

    $this->actingAs($user)
        ->get(listPage($list))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.mediaItemId', $film->id)
            ->where('items.0.imdbId', 'tt0133093')
            ->where('items.1.mediaItemId', null)
            ->where('items.1.imdbId', null));
});
