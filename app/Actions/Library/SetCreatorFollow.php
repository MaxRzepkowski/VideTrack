<?php

namespace App\Actions\Library;

use App\Actions\Media\PersistCreator;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Creators\Creators;
use App\Enums\FollowMode;
use App\Library\CollectionSponsorship;
use App\Library\Events\FollowChanged;
use App\Library\MutationContext;
use App\Library\Sponsorships;
use App\Models\Follow;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Writes the bell on a creator. Following backs the titles it has coming
 * (a collection at once, its parts still to be released; a person or a
 * company once their page has been read — see the FollowChanged listener);
 * muting a collection silences it without forgetting it; null forgets the
 * bell and withdraws everything the creator backed for the user.
 *
 * Clearing the bell of a creator nobody ever touched writes nothing and asks
 * no provider.
 */
final readonly class SetCreatorFollow
{
    public function __construct(
        private Creators $creators,
        private PersistCreator $persist,
        private Sponsorships $sponsorships,
        private CollectionSponsorship $collections,
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, CreatorRef $creator, ?FollowMode $mode, string $locale): void
    {
        $row = $this->creators->find($creator);

        if ($row === null && $mode === null) {
            return;
        }

        // Outside the transaction: a first follow may have to ask the provider.
        $row ??= $this->persist->execute($creator, $locale);
        $column = Follow::creatorColumn($creator->kind);

        DB::transaction(function () use ($user, $creator, $mode, $row, $column): void {
            $previous = $user->follows()->where($column, $row->id)->first()?->mode;

            if ($mode === null) {
                $user->follows()->where($column, $row->id)->delete();
            } else {
                $user->follows()->updateOrCreate([$column => $row->id], ['mode' => $mode]);
            }

            if ($mode === FollowMode::Follow && $row instanceof MediaList) {
                $this->collections->sponsorUpcomingParts($user, $row);
            } elseif ($mode !== FollowMode::Follow) {
                $this->sponsorships->withdraw($creator->kind, $row->id, $user);
            }

            if ($mode !== $previous) {
                Event::dispatch(new FollowChanged(
                    userId: $user->id,
                    source: $this->mutations->current(),
                    mode: $mode,
                    previousMode: $previous,
                    creatorKind: $creator->kind,
                    creatorId: $row->id,
                ));
            }
        });
    }
}
