<?php

use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a follow points at exactly one target', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $person = Person::factory()->create();
    $base = ['user_id' => $user->id, 'mode' => FollowMode::Follow];

    expect(fn () => Follow::query()->create($base))->toThrow(QueryException::class)
        ->and(fn () => Follow::factory()->create([...$base, 'media_item_id' => $movie->id, 'person_id' => $person->id]))
        ->toThrow(QueryException::class);

    Follow::factory()->create([...$base, 'media_item_id' => $movie->id]);

    expect(Follow::query()->count())->toBe(1);
});

test('a list entry points at exactly one target', function () {
    $user = User::factory()->create();
    $list = $user->lists()->create(['title' => 'Mixed']);
    $movie = MediaItem::factory()->movie()->create();
    $person = Person::factory()->create();
    $base = ['list_id' => $list->id, 'position' => 1];

    expect(fn () => ListItem::query()->create($base))->toThrow(QueryException::class)
        ->and(fn () => ListItem::query()->create([...$base, 'media_item_id' => $movie->id, 'person_id' => $person->id]))
        ->toThrow(QueryException::class);

    ListItem::query()->create([...$base, 'person_id' => $person->id]);

    expect(ListItem::query()->count())->toBe(1);
});
