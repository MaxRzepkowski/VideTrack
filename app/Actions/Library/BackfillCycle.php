<?php

namespace App\Actions\Library;

use App\Library\BackfillRules;
use App\Library\Events\RewatchStarted;
use App\Library\MutationContext;
use App\Models\MediaItem;
use App\Models\User;
use App\Models\WatchCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/**
 * A later run through a show from the user's history elsewhere (a restored
 * backup), with the day it began. Unlike {@see StartRewatch} it is a dated
 * fact, not a click: it needs no viewing in the run before, closes nothing
 * that is open, and a run of that number the library holds already stands.
 */
final readonly class BackfillCycle
{
    public function __construct(
        private BackfillRules $rules,
        private MutationContext $mutations,
    ) {}

    /**
     * @return WatchCycle|null the run of that number, written now or held already; null for a title without runs
     */
    public function execute(User $user, MediaItem $root, int $number, CarbonImmutable $startedAt): ?WatchCycle
    {
        $cycle = $this->rules->numberedCycle($user, $root, $number, $startedAt);

        if ($cycle !== null && $cycle->wasRecentlyCreated) {
            Event::dispatch(new RewatchStarted(userId: $user->id, source: $this->mutations->current(), rootId: $root->id, cycle: $cycle->number));
        }

        return $cycle;
    }
}
