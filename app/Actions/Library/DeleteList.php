<?php

namespace App\Actions\Library;

use App\Library\Events\ListDeleted;
use App\Library\MutationContext;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** Deletes one of the user's own lists with everything on it. */
final readonly class DeleteList
{
    public function __construct(private MutationContext $mutations) {}

    public function execute(User $user, MediaList $list): void
    {
        DB::transaction(function () use ($user, $list): void {
            $list->delete();

            Event::dispatch(new ListDeleted(userId: $user->id, source: $this->mutations->current(), listId: $list->id));
        });
    }
}
