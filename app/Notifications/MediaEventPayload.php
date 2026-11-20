<?php

namespace App\Notifications;

use App\Catalog\Data\MediaSummary;
use App\Catalog\DatePrecision;
use App\Catalog\RelatedReleaseCard;
use App\Models\User;
use App\Notifications\Events\EventKind;
use App\Support\Payload;
use App\Tracking\Surface;
use App\Tracking\TrackingSource;

/**
 * One media event as a notification stores it: what happened, to which
 * title, and the key that makes it happen once. The key is about the
 * position — a followed actor in a followed show still yields ONE ping, and a
 * catch-up re-run never doubles a day — so each kind of event builds its own
 * in its class ({@see EventKind}), not at the call site. A key holds as long
 * as its notification is kept (`tracker.retention.notifications_days`); past
 * that, what stops an event coming twice is its producer, which announces
 * only what changed.
 */
final readonly class MediaEventPayload
{
    /**
     * @param  array<array-key, mixed>  $media  the title's card, as stored
     * @param  list<array<array-key, mixed>>|null  $items  the cards of every title an event about several lists
     */
    public function __construct(
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

    /**
     * Whether the user's toggles let this event through; one with no toggle
     * always goes. A title the user's followed creators brought in also
     * answers to their row of the notifications, as its kind of title has it:
     * switching that row off silences what they bring, news of it included.
     */
    public function wantedBy(User $user): bool
    {
        $preference = $this->type?->preference();

        if ($preference !== null && ! $user->preferences->notifies($preference)) {
            return false;
        }

        // A tracked game's DLC, remake or remaster also answers to its kind's switch.
        $related = $this->relatedCard()?->kind();

        if ($related !== null && ! $user->preferences->showsRelated(Surface::Notifications, $related)) {
            return false;
        }

        return $this->type?->comesFromCreators() !== true
            || Surface::Notifications->takes($user->preferences, TrackingSource::CreatorFollow, Payload::string($this->media, 'type'));
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

    /** What a tracked game's release of its own (its DLC, a remake) is and whose; null for a title tracked in its own right. */
    public function relatedCard(): ?RelatedReleaseCard
    {
        return RelatedReleaseCard::of(Payload::map($this->media['extra'] ?? null));
    }

    /** The release a tracked game's DLC or remake news is about, by its ref; null for news of a title tracked in its own right. */
    public function relatedRef(): ?string
    {
        return $this->relatedCard() === null ? null : Payload::string($this->media, 'ref');
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
}
