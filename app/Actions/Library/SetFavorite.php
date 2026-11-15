<?php

namespace App\Actions\Library;

use App\Library\Events\FavoriteSet;
use App\Library\MutationContext;
use App\Models\Favorite;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/**
 * Sets the desired favorite state (not a toggle) — idempotent, so optimistic
 * double-clicks cannot flip the state back.
 */
final readonly class SetFavorite
{
    public function __construct(
        private MutationContext $mutations,
    ) {}

    /**
     * @param  CarbonImmutable|null  $since  when it became a favorite, where the history says so (an import); now otherwise
     * @return bool whether anything changed
     */
    public function execute(User $user, MediaItem $item, bool $state, ?CarbonImmutable $since = null): bool
    {
        if ($state) {
            $favorite = Favorite::query()->firstOrCreate(
                ['user_id' => $user->id, 'media_item_id' => $item->id],
                $since === null ? [] : ['created_at' => $since, 'updated_at' => $since],
            );

            // Idempotence extends to the event: repeating the desired state
            // changed nothing, so there is nothing to announce.
            if (! $favorite->wasRecentlyCreated) {
                return false;
            }

            $this->announce($user, $item, true);

            return true;
        }

        $deleted = Favorite::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->delete();

        if ($deleted === 0) {
            return false;
        }

        $this->announce($user, $item, false);

        return true;
    }

    private function announce(User $user, MediaItem $item, bool $favorite): void
    {
        Event::dispatch(new FavoriteSet(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $item->id,
            favorite: $favorite,
        ));
    }
}
