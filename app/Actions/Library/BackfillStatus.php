<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Enums\MediaUserStatus;
use App\Library\BackfillBatch;
use App\Library\BackfillRules;
use App\Library\Events\MediaStatusChanged;
use App\Library\Events\ProgressLogged;
use App\Library\MutationContext;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/**
 * An open attempt (on hold, in progress) from the user's history elsewhere,
 * on a position whose type holds one. An open attempt the user has here
 * stands; a viewing dated after it means it closed since, so it is not added
 * ({@see BackfillRules::completedAfter()}). A counter the first import lacked
 * fills in when both agree on the status.
 */
final readonly class BackfillStatus
{
    public function __construct(
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
        private BackfillRules $rules,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  int|null  $progress  the open attempt's counter (pages, chapters, minutes)
     * @return bool whether the open attempt was written
     */
    public function execute(User $user, MediaItem $item, MediaUserStatus $status, BackfillBatch $batch, ?CarbonImmutable $setAt = null, ?int $progress = null): bool
    {
        if (! $this->types->get($item->type)->takesOpenStatus()) {
            return false;
        }

        $kept = $this->rules->amount($item, $progress);
        $state = MediaUserState::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->first();

        if ($state !== null) {
            if ($state->status === $status && $state->progress === null && $kept !== null) {
                $state->update(['progress' => $kept]);
                Event::dispatch(new ProgressLogged(userId: $user->id, source: $this->mutations->current(), itemId: $item->id, added: null, set: $kept, progress: $kept));
            }

            return false;
        }

        if ($setAt !== null) {
            $root = $this->hierarchy->rootOf($item);

            if ($this->rules->completedAfter($user, $item, $this->rules->cycleAt($user, $root, $setAt), $setAt, $batch)) {
                return false;
            }
        }

        MediaUserState::query()->create([
            'user_id' => $user->id,
            'media_item_id' => $item->id,
            'status' => $status,
            'progress' => $kept,
            ...$this->rules->timestamps($setAt),
        ]);

        Event::dispatch(new MediaStatusChanged(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $item->id,
            status: $status,
            previous: null,
        ));

        return true;
    }
}
