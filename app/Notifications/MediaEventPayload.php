<?php

namespace App\Notifications;

use App\Catalog\Data\MediaSummary;
use App\Catalog\DatePrecision;
use App\Catalog\MediaItemSummaries;
use App\Enums\ReleaseKind;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use App\Support\Payload;
use App\Tracking\Releases\ReleaseEntry;
use InvalidArgumentException;

/**
 * One media event as a notification stores it: what happened, to which
 * title, and the key that makes it happen once. The key is about the
 * position — a followed actor in a followed show still yields ONE ping, and a
 * catch-up re-run never doubles a day — so each kind of event builds its own
 * here, not at the call site. A key holds as long as its notification is kept
 * (`tracker.retention.notifications_days`); past that, what stops an event
 * coming twice is its producer, which announces only what changed.
 */
final readonly class MediaEventPayload
{
    /**
     * @param  array<array-key, mixed>  $media  the title's card, as stored
     * @param  list<array<array-key, mixed>>|null  $items  the cards of every title an event about several lists
     */
    private function __construct(
        /** Null only for a stored event this version does not know (an old row, a newer server). */
        public ?MediaEventType $type,
        public string $key,
        public array $media,
        /** Episode events: the show the episode belongs to. */
        public ?string $showTitle = null,
        /** YYYY-MM-DD: the release the event concerns, or the new date. */
        public ?string $date = null,
        public ?string $oldDate = null,
        /** How much of `oldDate` was known; `date` says its own on the card (`media`). */
        public ?DatePrecision $oldDatePrecision = null,
        public ?string $status = null,
        public ?string $oldStatus = null,
        public ?int $season = null,
        public ?array $items = null,
        public ?string $personName = null,
        public ?string $companyName = null,
        public ?string $collectionTitle = null,
        /** The collection's address at its provider: its page is `collection/{provider}/{ref}`. */
        public ?string $collectionProvider = null,
        public ?string $collectionRef = null,
    ) {}

    /**
     * A notification's `data` as stored, whatever version wrote it: a field
     * that is missing or of the wrong type reads as absent.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromStored(array $data): self
    {
        $items = is_array($data['items'] ?? null) ? Payload::list($data['items']) : null;

        return new self(
            type: Payload::enum($data, 'event', MediaEventType::class),
            key: Payload::text($data, 'key'),
            media: Payload::map($data['media'] ?? null),
            showTitle: Payload::string($data, 'showTitle'),
            date: Payload::string($data, 'date'),
            oldDate: Payload::string($data, 'oldDate'),
            oldDatePrecision: Payload::enum($data, 'oldDatePrecision', DatePrecision::class),
            status: Payload::string($data, 'status'),
            oldStatus: Payload::string($data, 'oldStatus'),
            season: Payload::int($data, 'season'),
            items: $items,
            personName: Payload::string($data, 'personName'),
            companyName: Payload::string($data, 'companyName'),
            collectionTitle: Payload::string($data, 'collectionTitle'),
            collectionProvider: Payload::string($data, 'collectionProvider'),
            collectionRef: Payload::string($data, 'collectionRef'),
        );
    }

    /** A release reaching the user today: an aired episode, a premiere, a film leaving cinemas. */
    public static function released(ReleaseEntry $entry, string $today): self
    {
        $media = $entry->media;

        if ($media->releaseKind === ReleaseKind::Home) {
            return self::homeReleased($entry, $today);
        }

        $type = match (true) {
            ! $entry->isPart() => MediaEventType::Premiere,
            $entry->opensSeason() => MediaEventType::SeasonPremiere,
            default => MediaEventType::EpisodeAired,
        };

        return new self(
            type: $type,
            key: "released:{$media->type}:{$media->ref}:{$today}",
            media: $media->jsonSerialize(),
            showTitle: $entry->showTitle,
            date: $today,
            season: $media->seasonNumber,
        );
    }

    /**
     * Several episodes of one show out today, as one event that lists them: a
     * season dropped at once is one piece of news. It premieres the season
     * when one of them opens it, and leads with that one. Keyed by the show,
     * the day and the episodes, so a catch-up re-run says nothing new; its
     * producer leaves out the episodes already said, so the ones dated today
     * after the day's run arrive in a notification of their own.
     *
     * @param  list<ReleaseEntry>  $episodes  of one show, in season and episode order
     */
    public static function episodesReleased(array $episodes, string $today): self
    {
        if (count($episodes) < 2) {
            throw new InvalidArgumentException('One episode is released(); this lists several.');
        }

        $opener = array_find($episodes, fn (ReleaseEntry $entry): bool => $entry->opensSeason());
        $lead = $opener ?? $episodes[0];

        return new self(
            type: $opener === null ? MediaEventType::EpisodeAired : MediaEventType::SeasonPremiere,
            key: "episodes_released:{$lead->rootId}:{$today}:".md5(implode(',', array_map(fn (ReleaseEntry $entry): string => $entry->media->ref, $episodes))),
            media: $lead->media->jsonSerialize(),
            showTitle: $lead->showTitle,
            date: $today,
            season: $lead->media->seasonNumber,
            items: array_map(fn (ReleaseEntry $entry): array => $entry->media->jsonSerialize(), $episodes),
        );
    }

    /**
     * A film reaching homes today — also one premiering the same day, for a
     * viewer who follows home releases and not premieres. Its key is its own,
     * so it neither collides with the premiere of the same title nor changes
     * the keys stored for one.
     */
    public static function homeReleased(ReleaseEntry $entry, string $today): self
    {
        $media = $entry->media;

        return new self(
            type: MediaEventType::HomeRelease,
            key: "home-release:{$media->type}:{$media->ref}:{$today}",
            media: $media->jsonSerialize(),
            showTitle: $entry->showTitle,
            date: $today,
            season: $media->seasonNumber,
        );
    }

    /** A release date first announced or moved, still ahead. */
    /**
     * The title's date, as `$root` now holds it, moved from the one before —
     * or only sharpened: "2028" becoming 12 March 2028 is news about the day.
     */
    public static function dateChanged(MediaItem $root, ?string $oldDate, DatePrecision $oldPrecision): self
    {
        $date = $root->release_date?->toDateString() ?? throw new InvalidArgumentException('A moved date names the new one.');
        $precision = $root->release_date_precision;

        return new self(
            type: MediaEventType::DateChanged,
            // An exact day keeps the plain key.
            key: "date_changed:{$root->type}:{$root->provider_ref}:{$date}".($precision === DatePrecision::Day ? '' : ":{$precision->value}"),
            media: MediaItemSummaries::summary($root)->jsonSerialize(),
            date: $date,
            oldDate: $oldDate,
            oldDatePrecision: $oldDate === null || $oldPrecision === DatePrecision::Day ? null : $oldPrecision,
        );
    }

    public static function statusChanged(MediaItem $root, string $status, string $oldStatus): self
    {
        $slug = str($status)->slug()->value();

        return new self(
            type: MediaEventType::ShowStatus,
            key: "show_status:{$root->type}:{$root->provider_ref}:{$slug}",
            media: MediaItemSummaries::summary($root)->jsonSerialize(),
            status: $status,
            oldStatus: $oldStatus,
        );
    }

    public static function seasonAnnounced(MediaItem $root, int $season): self
    {
        return new self(
            type: MediaEventType::SeasonAnnounced,
            key: "season_announced:{$root->type}:{$root->provider_ref}:{$season}",
            media: MediaItemSummaries::summary($root)->jsonSerialize(),
            season: $season,
        );
    }

    /**
     * A new title of a followed person or company, named after them. Its key
     * is the title's own, shared with a collection's new part: whichever bell
     * finds the title first announces it, and the others find it said.
     */
    public static function creatorTitle(MediaEventType $type, MediaItem $item, string $creatorName): self
    {
        return new self(
            type: $type,
            key: self::newTitleKey($item),
            media: MediaItemSummaries::summary($item)->jsonSerialize(),
            date: $item->release_date?->toDateString(),
            personName: $type === MediaEventType::PersonNewTitle ? $creatorName : null,
            companyName: $type === MediaEventType::CompanyNewTitle ? $creatorName : null,
        );
    }

    /**
     * Parts a followed collection gained. One part is a new title, keyed as a
     * creator's is, so a re-run of the weekly sync never repeats it and a
     * director's bell that said it first is not echoed. Several at once (a
     * re-indexed saga) are one event that lists them, keyed by the set.
     *
     * @param  list<MediaItem>  $parts
     */
    public static function collectionParts(MediaList $collection, array $parts): self
    {
        $first = $parts[0] ?? throw new InvalidArgumentException('A collection event names at least one part.');
        $source = $collection->collectionSource() ?? throw new InvalidArgumentException('Only a provider collection gains parts.');
        $summaries = array_map(fn (MediaItem $item): array => MediaItemSummaries::summary($item)->jsonSerialize(), $parts);
        $several = count($parts) > 1;
        $ids = array_map(fn (MediaItem $item): int => $item->id, $parts);
        sort($ids);

        return new self(
            type: MediaEventType::CollectionNewItem,
            key: $several
                ? "collection_items:{$collection->id}:".md5(implode(',', $ids))
                : self::newTitleKey($first),
            media: $summaries[0],
            date: $several ? null : $first->release_date?->toDateString(),
            items: $several ? $summaries : null,
            collectionTitle: $collection->displayTitle(),
            collectionProvider: $source['provider'],
            collectionRef: $source['ref'],
        );
    }

    /**
     * Every title of the user's library that became an archive entry in one
     * refresh, as one event.
     *
     * @param  list<MediaItem>  $items
     */
    public static function titlesRemoved(array $items): self
    {
        if ($items === []) {
            throw new InvalidArgumentException('A removal event names at least one title.');
        }

        $summaries = array_map(fn (MediaItem $item): array => MediaItemSummaries::summary($item)->jsonSerialize(), $items);

        return new self(
            type: MediaEventType::TitlesRemoved,
            key: 'titles_removed:'.md5(implode(',', array_map(fn (MediaItem $item): int => $item->id, $items))),
            media: $summaries[0],
            items: $summaries,
        );
    }

    /** Whether the user's toggles let this event through; one with no toggle always goes. */
    public function wantedBy(User $user): bool
    {
        $preference = $this->type?->preference();

        return $preference === null || $user->preferences->notifies($preference);
    }

    /**
     * How much of `date` is known. The date is the release of the title the
     * card shows, so the card says it; a card stored without a precision
     * has an exact day.
     */
    public function datePrecision(): DatePrecision
    {
        $extra = Payload::map($this->media['extra'] ?? null);

        return Payload::enum($extra, 'releasePrecision', DatePrecision::class) ?? DatePrecision::Day;
    }

    /** The name of the title the event is about. */
    public function title(): string
    {
        return (string) Payload::string($this->media, 'title');
    }

    /**
     * The titles the event lists: every one of an event about several, else
     * the one it is about.
     *
     * @return list<string>
     */
    public function titles(): array
    {
        return array_map(fn (array $item): string => (string) Payload::string($item, 'title'), $this->items ?? [$this->media]);
    }

    /**
     * The `type:ref` of every title the event lists, as `MediaSummary::key()`
     * names a card.
     *
     * @return list<string>
     */
    public function cardKeys(): array
    {
        $keys = [];

        foreach ($this->items ?? [$this->media] as $card) {
            $type = Payload::string($card, 'type');
            $ref = Payload::string($card, 'ref');

            if ($type !== null && $ref !== null) {
                $keys[] = MediaSummary::keyOf($type, $ref);
            }
        }

        return $keys;
    }

    /** An event about several titles has a page of its own. */
    public function listsSeveral(): bool
    {
        return count($this->items ?? []) > 1;
    }

    /**
     * The `data` column as clients read it: every field, the
     * missing ones null, the optional ones only when the event has them.
     *
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return [
            'key' => $this->key,
            'event' => $this->type?->value,
            'media' => $this->media,
            ...($this->items === null ? [] : ['items' => $this->items]),
            'showTitle' => $this->showTitle,
            'date' => $this->date,
            'oldDate' => $this->oldDate,
            ...($this->oldDatePrecision === null ? [] : ['oldDatePrecision' => $this->oldDatePrecision->value]),
            'status' => $this->status,
            'oldStatus' => $this->oldStatus,
            'season' => $this->season,
            ...($this->personName === null ? [] : ['personName' => $this->personName]),
            ...($this->companyName === null ? [] : ['companyName' => $this->companyName]),
            ...($this->collectionTitle === null ? [] : [
                'collectionTitle' => $this->collectionTitle,
                'collectionProvider' => $this->collectionProvider,
                'collectionRef' => $this->collectionRef,
            ]),
        ];
    }

    /** The fact "this title is new", whichever bell brought it. */
    private static function newTitleKey(MediaItem $item): string
    {
        return "new_title:{$item->type}:{$item->provider_ref}";
    }
}
