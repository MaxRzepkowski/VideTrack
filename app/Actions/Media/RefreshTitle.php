<?php

namespace App\Actions\Media;

use App\Catalog\ItemNotFoundException;
use App\Catalog\ScheduleFingerprint;
use App\Catalog\TitleStore;
use App\Models\MediaItem;
use App\Notifications\MediaEventPayload;
use App\Support\ExternalServiceException;
use App\Tracking\EpisodeTimes;
use App\Tracking\MetadataChanges;
use App\Tracking\Releases\OwnStances;
use App\Tracking\TrackedAudience;

/**
 * Brings a stored title up to what its provider says now — the parts it no
 * longer lists retired — and lets the users tracking it know what changed: a moved date, a new status, a new season —
 * the difference between before and after, each user's toggles honoured.
 */
final readonly class RefreshTitle
{
    public function __construct(
        private TitleStore $titles,
        private MetadataChanges $changes,
        private TrackedAudience $audience,
        private SendMediaNotification $send,
        private RetireGoneParts $retireParts,
        private ScheduleFingerprint $fingerprint,
        private EpisodeTimes $episodeTimes,
        private OwnStances $stances,
    ) {}

    /**
     * @return int notifications sent
     *
     * @throws ItemNotFoundException the provider no longer has it
     * @throws ExternalServiceException the provider could not be asked
     */
    public function execute(MediaItem $root, string $locale): int
    {
        $before = $this->changes->snapshot($root);
        $schedule = $this->fingerprint->of($root);

        $this->retireParts->execute($root, $this->titles->refresh($root, $locale));

        $events = $this->changes->diff($root->refresh(), $before);
        $sent = $events === [] ? 0 : $this->notify($root, $events);

        $this->episodeTimes->afterRefresh($root, $schedule !== $this->fingerprint->of($root));

        return $sent;
    }

    /**
     * @param  list<MediaEventPayload>  $events
     */
    private function notify(MediaItem $root, array $events): int
    {
        $sent = 0;
        // The news of a game's DLC or remake, by the release it is about.
        $related = [];

        foreach ($events as $index => $event) {
            $ref = $event->relatedRef();

            if ($ref !== null) {
                $related[$index] = $ref;
            }
        }

        // A release a user keeps as a title of their own is told of as that title, not as the game's.
        $own = $this->stances->ofEveryone($root->provider, $root->type, array_values(array_unique($related)));

        foreach ($this->audience->usersFor($root) as $user) {
            foreach ($events as $index => $event) {
                if (isset($related[$index], $own[$user->id][$related[$index]])) {
                    continue;
                }

                if ($event->wantedBy($user) && $this->send->execute($user, $event)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }
}
