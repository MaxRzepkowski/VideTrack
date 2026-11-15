<?php

namespace App\Actions\Library;

use App\Library\BackfillRules;
use App\Library\Events\RatingChanged;
use App\Library\MutationContext;
use App\Library\RatingScale;
use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use LogicException;

/**
 * A score from the user's history elsewhere. A score the user already gave
 * here stands — unlike a click ({@see RateItem}), history never replaces
 * one. Dated at the source, so the activity shows when it really happened.
 * The caller reports a score off the scale; one reaching here is a bug.
 */
final readonly class BackfillRating
{
    public function __construct(
        private BackfillRules $rules,
        private MutationContext $mutations,
    ) {}

    /** @return bool whether the score was written */
    public function execute(User $user, MediaItem $item, int $rating, ?CarbonImmutable $ratedAt = null): bool
    {
        if (! RatingScale::holds($rating)) {
            throw new LogicException("A rating of {$rating} is off the scale.");
        }

        $record = Rating::query()->firstOrCreate(
            ['user_id' => $user->id, 'media_item_id' => $item->id],
            ['rating' => $rating, ...$this->rules->timestamps($ratedAt)],
        );

        if (! $record->wasRecentlyCreated) {
            return false;
        }

        Event::dispatch(new RatingChanged(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $item->id,
            rating: $rating,
            previous: null,
        ));

        return true;
    }
}
