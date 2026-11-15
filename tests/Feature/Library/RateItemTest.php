<?php

use App\Actions\Library\RateItem;
use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a score raced by another request lands on the row that request wrote', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    // The other tab inserts its score between this write's read and its insert.
    $raced = false;
    Rating::creating(function () use (&$raced, $user, $movie): void {
        if (! $raced) {
            $raced = true;
            DB::table('ratings')->insert(['user_id' => $user->id, 'media_item_id' => $movie->id, 'rating' => 4, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $rating = app(RateItem::class)->execute($user, $movie, 9);

    expect($rating->rating)->toBe(9)
        ->and(Rating::query()->where('user_id', $user->id)->pluck('rating')->all())->toBe([9]);
});
