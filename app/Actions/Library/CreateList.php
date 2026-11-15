<?php

namespace App\Actions\Library;

use App\Library\Events\ListCreated;
use App\Library\ListPositions;
use App\Library\MutationContext;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/** A new custom list, at the end of the user's lists. */
final readonly class CreateList
{
    public function __construct(
        private ListPositions $positions,
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, string $title, ?string $description = null, bool $isPublic = true): MediaList
    {
        /** @var MediaList $list */
        $list = $user->lists()->create([
            'title' => $title,
            'description' => $description,
            'is_public' => $isPublic,
            'position' => $this->positions->nextListPosition($user),
        ]);

        Event::dispatch(new ListCreated(userId: $user->id, source: $this->mutations->current(), listId: $list->id));

        return $list;
    }
}
