<?php

namespace App\Tracking;

use App\Catalog\DatePrecision;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\TitleStore;
use App\Models\MediaItem;
use App\Notifications\MediaEventPayload;
use App\Support\Numbers;

/**
 * Diff-based media events around a metadata refresh: the caller snapshots a
 * root before TitleStore::refresh() and diffs after. Detected
 * here: a moved (or first-announced) release date still in the future, a show
 * status change and a newly announced season. Date-REACHED events (aired
 * episodes, premieres) are not diffs — the daily notify-releases task derives
 * them from the same engine as the calendar.
 */
final readonly class MetadataChanges
{
    public function __construct(private MediaTypeRegistry $types) {}

    /**
     * @return array{release_date: string|null, release_date_precision: DatePrecision, status: string|null, maxSeason: int|null}
     */
    public function snapshot(MediaItem $root): array
    {
        return [
            'release_date' => $root->release_date?->toDateString(),
            'release_date_precision' => $root->release_date_precision,
            'status' => $this->status($root),
            'maxSeason' => $this->maxSeason($root),
        ];
    }

    /**
     * Events for a freshly refreshed root against its snapshot — payloads
     * without an audience (the caller resolves users and event preferences).
     *
     * @param  array{release_date: string|null, release_date_precision: DatePrecision, status: string|null, maxSeason: int|null}  $before
     * @return list<MediaEventPayload>
     */
    public function diff(MediaItem $root, array $before): array
    {
        $events = [];

        $date = $root->release_date?->toDateString();
        $precision = $root->release_date_precision;
        // The same day read as vaguer is our reading catching up with what
        // the provider always meant ("2028" was never 31 December), not news.
        $moved = $date !== $before['release_date'] || $precision->isFinerThan($before['release_date_precision']);

        // A vanished date is provider noise, not news; a first-announced,
        // moved or sharpened one ("2028" becoming a day) is worth a ping —
        // but only while it is still ahead. A provider correcting when
        // something came out years ago is housekeeping, and announcing it
        // would be the app inventing news out of a metadata fix. Today counts
        // as ahead: the release is happening.
        if ($date !== null && $moved && $precision->lastDay($date) >= today()->toDateString()) {
            $events[] = MediaEventPayload::dateChanged($root, $before['release_date'], $before['release_date_precision']);
        }

        $status = $this->status($root);

        if ($status !== null && $before['status'] !== null && $status !== $before['status']) {
            $events[] = MediaEventPayload::statusChanged($root, $status, $before['status']);
        }

        $maxSeason = $this->maxSeason($root);

        if ($maxSeason !== null && $before['maxSeason'] !== null && $maxSeason > $before['maxSeason']) {
            $events[] = MediaEventPayload::seasonAnnounced($root, $maxSeason);
        }

        return $events;
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
