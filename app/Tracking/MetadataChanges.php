<?php

namespace App\Tracking;

use App\Accounts\Preferences\NotificationEvent;
use App\Accounts\Preferences\UserPreferences;
use App\Catalog\DatePrecision;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\TitleStore;
use App\Models\MediaItem;
use App\Notifications\MediaEventPayload;
use App\Support\Numbers;
use App\Tracking\Releases\RelatedReleases;
use App\Tracking\Settings\DeclaresTypeOptions;
use App\Tracking\Settings\TypeOption;

/**
 * Diff-based media events around a metadata refresh: the caller snapshots a
 * root before TitleStore::refresh() and diffs after. Detected
 * here: a moved (or first-announced) release date still in the future, a show
 * status change and a newly announced season, which takes in the status
 * change that only says the show is back; for a game, its DLC, remakes and
 * remasters still to come, newly listed or moved. Date-REACHED events (aired
 * episodes, premieres) are not diffs — the daily notify-releases task derives
 * them from the same engine as the calendar.
 *
 * @phpstan-type Snapshot array{
 *     release_date: string|null,
 *     release_date_precision: DatePrecision,
 *     status: string|null,
 *     maxSeason: int|null,
 *     related: array<string, array{date: string, precision: DatePrecision}>|null
 * }
 */
final readonly class MetadataChanges implements DeclaresTypeOptions
{
    public function __construct(
        private MediaTypeRegistry $types,
        private RelatedReleases $related,
    ) {}

    /**
     * A title released in parts has a status and seasons to come; a game
     * whose provider lists its DLC, remakes and remasters has those to be
     * announced. A moved date is news for every kind of title, so it is not
     * a type's own.
     */
    public function typeOptions(MediaTypeDefinition $type): array
    {
        $options = [];

        if ($type->childType !== null) {
            $options[] = TypeOption::on(
                key: 'status',
                label: 'settings.show_status_and_new_seasons',
                description: 'settings.a_tracked_show_gets_a_new',
                phrase: 'settings.phrase_show_status',
                surfaces: [Surface::Notifications],
                preference: fn (): string => UserPreferences::notifiesKey(NotificationEvent::ShowStatus),
            );
        }

        if ($this->related->serves($type)) {
            $options[] = TypeOption::on(
                key: 'related_announced',
                label: 'settings.new_dlc_remakes_and_remasters',
                description: 'settings.announced_for_a_tracked_game',
                phrase: 'settings.phrase_related_announced',
                surfaces: [Surface::Notifications],
                preference: fn (): string => UserPreferences::notifiesKey(NotificationEvent::RelatedAnnounced),
            );
        }

        return $options;
    }

    /**
     * @return Snapshot
     */
    public function snapshot(MediaItem $root): array
    {
        return [
            'release_date' => $root->release_date?->toDateString(),
            'release_date_precision' => $root->release_date_precision,
            'status' => $this->status($root),
            'maxSeason' => $this->maxSeason($root),
            'related' => $this->relatedDates($root),
        ];
    }

    /**
     * Events for a freshly refreshed root against its snapshot — payloads
     * without an audience (the caller resolves users and event preferences).
     *
     * @param  Snapshot  $before
     * @return list<MediaEventPayload>
     */
    public function diff(MediaItem $root, array $before): array
    {
        $events = [];

        $date = $root->release_date?->toDateString();
        $precision = $root->release_date_precision;
        // A vanished date is provider noise, not news.
        if ($date !== null && $this->isDateNews($date, $precision, $before['release_date'], $before['release_date_precision'])) {
            $events[] = MediaEventPayload::dateChanged($root, $before['release_date'], $before['release_date_precision']);
        }

        $maxSeason = $this->maxSeason($root);
        $newSeason = $maxSeason !== null && $before['maxSeason'] !== null && $maxSeason > $before['maxSeason'];
        $status = $this->status($root);

        // A renewed show comes back with its next season: the season says
        // it, and the status turning to running is the same news. A status
        // that says anything else is its own.
        $cameBack = $newSeason && $root->status?->isRunning() === true;

        if ($status !== null && $before['status'] !== null && $status !== $before['status'] && ! $cameBack) {
            $events[] = MediaEventPayload::statusChanged($root, $status, $before['status']);
        }

        if ($newSeason) {
            $events[] = MediaEventPayload::seasonAnnounced($root, $maxSeason);
        }

        return [...$events, ...$this->relatedEvents($root, $before['related'])];
    }

    /**
     * A game's DLC, remakes and remasters still to come: one listed for the
     * first time is announced, one already listed whose date moved or
     * sharpened is a moved date, as the game's own would be. A game whose
     * provider had never listed them before has nothing to compare: every
     * one of them would read as just announced.
     *
     * @param  array<string, array{date: string, precision: DatePrecision}>|null  $before
     * @return list<MediaEventPayload>
     */
    private function relatedEvents(MediaItem $root, ?array $before): array
    {
        if ($before === null) {
            return [];
        }

        $events = [];

        foreach (ItemMetadata::fromStored($root->metadata)->relatedReleases ?? [] as $release) {
            $was = $before[$release->ref] ?? null;

            if ($was === null && $release->precision->isStillAhead($release->date, today()->toDateString())) {
                $events[] = MediaEventPayload::relatedAnnounced($root, $release);
            } elseif ($was !== null && $this->isDateNews($release->date, $release->precision, $was['date'], $was['precision'])) {
                $events[] = MediaEventPayload::relatedDateChanged($root, $release, $was['date'], $was['precision']);
            }
        }

        return $events;
    }

    /**
     * A first-announced, moved or sharpened date ("2028" becoming a day) —
     * the same day read as vaguer is our reading catching up with what the
     * provider always meant ("2028" was never 31 December) — while it is
     * still ahead. A provider correcting when something came out years ago is
     * housekeeping, and announcing it would be the app inventing news out of
     * a metadata fix. Today counts as ahead: the release is happening.
     */
    private function isDateNews(string $date, DatePrecision $precision, ?string $oldDate, DatePrecision $oldPrecision): bool
    {
        return ($date !== $oldDate || $precision->isFinerThan($oldPrecision)) && $precision->isStillAhead($date, today()->toDateString());
    }

    /**
     * The first date of each of a game's DLC, remakes and remasters, by ref;
     * null when its provider never listed them.
     *
     * @return array<string, array{date: string, precision: DatePrecision}>|null
     */
    private function relatedDates(MediaItem $root): ?array
    {
        $related = ItemMetadata::fromStored($root->metadata)->relatedReleases;

        if ($related === null) {
            return null;
        }

        $dates = [];

        foreach ($related as $release) {
            $dates[$release->ref] = ['date' => $release->date, 'precision' => $release->precision];
        }

        return $dates;
    }

    private function status(MediaItem $root): ?string
    {
        return $root->status?->value;
    }

    private function maxSeason(MediaItem $root): ?int
    {
        if ($this->types->get($root->type)->childType === null) {
            return null;
        }

        return Numbers::int(MediaItem::query()->where('parent_id', $root->id)->max('number'));
    }
}
