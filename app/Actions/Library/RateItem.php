<?php

namespace App\Actions\Library;

use App\Library\Events\RatingChanged;
use App\Library\MutationContext;
use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;

/**
 * Sets the user's current 1–10 rating — one row per position, any type.
 */
final readonly class RateItem
{
    public function __construct(
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, MediaItem $item, int $rating): Rating
    {
        try {
            return $this->write($user, $item, $rating);
        } catch (UniqueConstraintViolationException) {
            // Another request scored the title between the read and the
            // write (a second tab, a double click): this score replaces that one.
            return $this->write($user, $item, $rating);
        }
    }

    private function write(User $user, MediaItem $item, int $rating): Rating
    {
        // Read before the write: the event carries what this score replaced.
        $record = Rating::query()->firstOrNew(['user_id' => $user->id, 'media_item_id' => $item->id]);
        $previous = $record->exists ? $record->rating : null;

        // Giving the score it already has changes nothing, and is no fact.
        if ($previous === $rating) {
            return $record;
        }

        $record->rating = $rating;
        $record->save();

        Event::dispatch(new RatingChanged(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $item->id,
            rating: $rating,
            previous: $previous,
        ));

        return $record;
    }
}
