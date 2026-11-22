<?php

namespace App\Notifications;

use App\Catalog\ImageUrls;
use App\Catalog\MediaTypeRegistry;
use App\Http\CardPages;
use App\Notifications\Copy\CopyFormat;
use App\Notifications\Data\EventLine;
use App\Notifications\Data\NotificationEntry;
use App\Notifications\Events\EventKinds;
use App\Support\Payload;
use App\Support\PublicUrl;
use Illuminate\Notifications\DatabaseNotification;

/**
 * What a media event says, in the recipient's language, and where it leads:
 * the words come from its kind ({@see EventKinds}), the links, the picture and
 * the colour are the same for every kind. The bell, the notifications pages
 * and the API receive it finished; a notification channel gets the same
 * words as plain text.
 */
final readonly class NotificationCopy
{
    /** The brand purple, for a title of no known type and for what is about no title. */
    public const BRAND_COLOR = 0x7337FF;

    public function __construct(
        private MediaTypeRegistry $types,
        private CardPages $pages,
        private EventKinds $kinds,
        private CopyFormat $format,
    ) {}

    /**
     * A stored notification as the bell, the notifications pages and the API
     * show it: what it says, where it leads and its picture.
     */
    public function entry(DatabaseNotification $notification, string $locale): NotificationEntry
    {
        $event = MediaEventPayload::fromStored($notification->data);

        return new NotificationEntry(
            id: $notification->id,
            readAt: $notification->read_at?->toIso8601String(),
            createdAt: $notification->created_at?->toIso8601String(),
            event: $event->type,
            title: $this->title($event, $locale),
            detail: $this->detail($event, $locale),
            href: $this->href($notification->id, $event),
            imagePath: $this->imagePath($event),
            mediaTitle: $event->title(),
            data: $notification->data,
        );
    }

    /** Headline of the event; a stored event of a kind this version does not know is named by its title. */
    public function title(MediaEventPayload $event, string $locale): string
    {
        return $this->kinds->of($event->type)?->title($event, $locale) ?? $event->title();
    }

    /**
     * The facts under the headline — episode code, moved date, status
     * change… — each its own part: a screen sets them apart with a hairline,
     * plain text joins them.
     *
     * @return list<string>
     */
    public function detail(MediaEventPayload $event, string $locale): array
    {
        return $this->kinds->of($event->type)?->detail($event, $locale) ?? [];
    }

    /**
     * Where the notification leads in the app: its own page for one that
     * lists several titles (it names them all), else the title's page.
     */
    public function href(string $notificationId, MediaEventPayload $event): string
    {
        return $event->type === MediaEventType::TitlesRemoved || $event->listsSeveral()
            ? route('notifications.show', ['notification' => $notificationId])
            : url($this->titlePath($event->media));
    }

    /**
     * Where a channel's message links: a message has no notification page to
     * open, so a list of titles leads to the archive, or to the collection
     * that gained them. The message reaches other people, so the address is
     * the instance's own, not the requesting browser's host.
     */
    public function url(MediaEventPayload $event): string
    {
        if ($event->type === MediaEventType::TitlesRemoved) {
            return PublicUrl::to(route('library.archived', absolute: false));
        }

        // A notification without a collection address leads to its first
        // title instead.
        if ($event->type === MediaEventType::CollectionNewItem && $event->listsSeveral() && $event->collectionProvider !== null && $event->collectionRef !== null) {
            return PublicUrl::to(route('collections.show', ['provider' => $event->collectionProvider, 'ref' => $event->collectionRef], absolute: false));
        }

        return PublicUrl::to($this->titlePath($event->media));
    }

    /**
     * The image a notification shows: poster, else backdrop (an episode's
     * still), else its show's backdrop. A provider path; the client sizes it.
     */
    public function imagePath(MediaEventPayload $event): ?string
    {
        $media = $event->media;

        return Payload::string($media, 'posterPath')
            ?? Payload::string($media, 'backdropPath')
            ?? Payload::string(is_array($media['extra'] ?? null) ? $media['extra'] : [], 'showBackdrop');
    }

    /** The detail as one line of plain text; null when there is none. */
    public function plainDetail(MediaEventPayload $event, string $locale): ?string
    {
        return $this->format->plain($this->detail($event, $locale));
    }

    /** The detail as plain text with the air time after it, when the position has one. */
    public function timedDetail(MediaEventPayload $event, string $locale, string $timezone): ?string
    {
        return $this->format->timed($this->detail($event, $locale), $event, $locale, $timezone);
    }

    /**
     * One digest line in parts: `subject` (the linked title), `label` (the
     * event spelled out) and `meta` (the facts it adds).
     *
     * @return array{subject: string, label: string, meta: string|null}
     */
    public function digestParts(MediaEventPayload $event, string $locale, string $timezone): array
    {
        $line = $this->line($event, $locale);
        $facts = [...$line->facts, ...$this->format->change($line->change)];

        return [
            'subject' => $line->subject,
            'label' => $line->label,
            'meta' => $line->atAirTime ? $this->format->timed($facts, $event, $locale, $timezone) : $this->format->plain($facts),
        ];
    }

    /** The event as a line of a list; a stored event of a kind this version does not know is its title alone. */
    private function line(MediaEventPayload $event, string $locale): EventLine
    {
        return $this->kinds->of($event->type)?->line($event, $locale)
            ?? new EventLine(subject: $event->title(), label: '');
    }

    /** The title type's palette colour as an RGB int; a part takes its root's. */
    public function color(MediaEventPayload $event): int
    {
        $type = Payload::string($event->media, 'type');

        if ($type === null || ! $this->types->has($type)) {
            return self::BRAND_COLOR;
        }

        foreach ($this->types->lineage($type) as $definition) {
            if ($definition->color !== null) {
                return (int) hexdec(ltrim($definition->color, '#'));
            }
        }

        return self::BRAND_COLOR;
    }

    /** Absolute image URL for an embed thumbnail: TMDB paths get the CDN base, other providers' URLs pass through. */
    public function imageUrl(MediaEventPayload $event): ?string
    {
        return ImageUrls::of($this->imagePath($event), ImageUrls::POSTER);
    }

    /**
     * The details page of a title card, as a path.
     *
     * @param  array<array-key, mixed>  $media
     */
    private function titlePath(array $media): string
    {
        $type = Payload::string($media, 'type');

        return $type === null
            ? route('search', absolute: false)
            : $this->pages->path($type, (string) Payload::string($media, 'ref'));
    }
}
