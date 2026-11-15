<?php

namespace App\Actions\Library;

use App\Library\Events\ListUpdated;
use App\Library\MutationContext;
use App\Models\MediaList;
use Illuminate\Support\Facades\Event;

/**
 * Edits a list's details. The system watchlist keeps its identity (title
 * translated in the UI, no description) — only visibility may change.
 */
final readonly class UpdateList
{
    public function __construct(private MutationContext $mutations) {}

    public function execute(MediaList $list, string $title, ?string $description, bool $isPublic): MediaList
    {
        $list->fill($list->isSystem()
            ? ['is_public' => $isPublic]
            : ['title' => $title, 'description' => $description, 'is_public' => $isPublic]);

        $list->save();

        if ($list->user_id !== null && $list->wasChanged()) {
            Event::dispatch(new ListUpdated(userId: $list->user_id, source: $this->mutations->current(), listId: $list->id));
        }

        return $list;
    }
}
