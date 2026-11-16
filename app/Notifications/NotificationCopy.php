<?php

namespace App\Notifications;

use App\Catalog\DatePrecision;
use App\Catalog\ImageUrls;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\TitleStatus;
use App\Http\CardPages;
use App\Notifications\Data\NotificationEntry;
use App\Support\Payload;
use App\Support\PublicUrl;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * What a media event says, in the recipient's language: the one place its
 * copy is written. The bell, the notifications pages and the API receive it
 * finished; Discord gets the same words as plain text.
 */
final readonly class NotificationCopy
{
    /** The brand purple, for a title of no known type. */
    private const BRAND_COLOR = 0x7337FF;

    /** How many titles a notification names before the rest is left to its page. */
    private const PREVIEW_TITLES = 3;

    /** Discord carries plain text, so the facts a screen sets apart with a hairline are joined by a dash. */
    private const PLAIN_SEPARATOR = ' – ';

    public function __construct(
        private MediaTypeRegistry $types,
        private CardPages $pages,
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

    /** Headline of the event. */
    public function title(MediaEventPayload $event, string $locale): string
    {
        $title = $event->title();

        return match ($event->type) {
            MediaEventType::EpisodeAired => __('notifications.new_episode_of_title', ['title' => $event->showTitle ?? $title], $locale),
            MediaEventType::SeasonPremiere => __('notifications.season_premiere_of_title', ['title' => $event->showTitle ?? $title], $locale),
            MediaEventType::Premiere => __('notifications.out_today_title', ['title' => $title], $locale),
            MediaEventType::HomeRelease => __('notifications.now_available_outside_cinemas_title', ['title' => $title], $locale),
            MediaEventType::DateChanged => __('notifications.new_release_date_for_title', ['title' => $title], $locale),
            MediaEventType::ShowStatus => __('notifications.title_changed_status', ['title' => $title], $locale),
            MediaEventType::SeasonAnnounced => __('notifications.new_season_of_title_announced', ['title' => $title], $locale),
            MediaEventType::PersonNewTitle => __('notifications.new_title_with_name', ['name' => $event->personName ?? ''], $locale),
            MediaEventType::CompanyNewTitle => __('notifications.new_title_from_name', ['name' => $event->companyName ?? ''], $locale),
            // The series has to be named — the part's own title says nothing
            // about which collection just grew.
            MediaEventType::CollectionNewItem => $this->collectionGrew($event, $locale),
            MediaEventType::TitlesRemoved => trans_choice('notifications.count_title_from_your_library_is', count($event->titles()), [], $locale),
            null => $title,
        };
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
        $media = $event->media;

        switch ($event->type) {
            case MediaEventType::EpisodeAired:
            case MediaEventType::SeasonPremiere:
                $extra = is_array($media['extra'] ?? null) ? $media['extra'] : [];
                $season = $event->season ?? Payload::positiveInt($extra, 'seasonNumber') ?? 0;
                $code = sprintf('S%02dE%02d', $season, Payload::positiveInt($media, 'number') ?? 0);

                return $event->title() !== '' ? [$code, $event->title()] : [$code];
            case MediaEventType::Premiere:
                $platform = Payload::string(is_array($media['extra'] ?? null) ? $media['extra'] : [], 'platform');

                return $platform === null ? [] : [$platform];
            case MediaEventType::HomeRelease:
                // Nothing useful to add: the source knows the day, not the
                // service, and naming a medium would overstate the data.
                return [];
            case MediaEventType::DateChanged:
                $next = $this->date($event->date, $event->datePrecision(), $locale);

                if ($next === null) {
                    return [];
                }

                $previous = $this->date($event->oldDate, $event->oldDatePrecision ?? DatePrecision::Day, $locale);

                // An arrow, not a separator: a date MOVED is one fact.
                return [$previous !== null ? "{$previous} → {$next}" : $next];
            case MediaEventType::ShowStatus:
                $status = TitleStatus::tryFrom((string) $event->status);

                if ($status === null) {
                    return [];
                }

                $old = TitleStatus::tryFrom((string) $event->oldStatus);

                return [$old === null ? $status->label($locale) : "{$old->label($locale)} → {$status->label($locale)}"];
            case MediaEventType::SeasonAnnounced:
                return [__('common.season_number', ['number' => (string) $event->season], $locale)];
            case MediaEventType::CollectionNewItem:
            case MediaEventType::PersonNewTitle:
            case MediaEventType::CompanyNewTitle:
                if ($event->listsSeveral()) {
                    return $this->titlesPreview($event, $locale);
                }

                $date = $this->date($event->date, $event->datePrecision(), $locale);

                return $date !== null ? [$event->title(), $date] : [$event->title()];
            case MediaEventType::TitlesRemoved:
                return $this->titlesPreview($event, $locale);
            default:
                return [];
        }
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
     * Where a Discord message links: a message has no notification page to
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
        $parts = $this->detail($event, $locale);

        return $parts === [] ? null : implode(self::PLAIN_SEPARATOR, $parts);
    }

    /**
     * One digest line decomposed for "what happened" readability:
     * `subject` (the linked media name), `label` (the event, spelled out —
     * "New episode", "Out today", "Status change"…) and `meta` (episode code
     * with air time, moved dates, platform…). The digest joins them as
     * "subject — label: meta", so every row says what it means, unlike the
     * bell feed where the icon and grouping carry that context.
     *
     * @return array{subject: string, label: string, meta: string|null}
     */
    public function digestParts(MediaEventPayload $event, string $locale, string $timezone): array
    {
        $title = $event->title();
        $date = $this->date($event->date, $event->datePrecision(), $locale);

        switch ($event->type) {
            case MediaEventType::EpisodeAired:
            case MediaEventType::SeasonPremiere:
                $meta = $this->plainDetail($event, $locale);
                $time = $this->airTime($event, $locale, $timezone);

                if ($time !== null) {
                    $meta = $meta === null ? "({$time})" : "{$meta} ({$time})";
                }

                return [
                    'subject' => $event->showTitle ?? $title,
                    'label' => $event->type === MediaEventType::SeasonPremiere
                        ? __('notifications.season_premiere', [], $locale)
                        : __('notifications.new_episode', [], $locale),
                    'meta' => $meta,
                ];
            case MediaEventType::Premiere:
                return ['subject' => $title, 'label' => __('notifications.out_today', [], $locale), 'meta' => $this->plainDetail($event, $locale)];
            case MediaEventType::HomeRelease:
                return ['subject' => $title, 'label' => __('common.outside_cinemas', [], $locale), 'meta' => null];
            case MediaEventType::DateChanged:
                return ['subject' => $title, 'label' => __('notifications.new_release_date', [], $locale), 'meta' => $this->plainDetail($event, $locale)];
            case MediaEventType::ShowStatus:
                return ['subject' => $title, 'label' => __('notifications.status_change', [], $locale), 'meta' => $this->plainDetail($event, $locale)];
            case MediaEventType::SeasonAnnounced:
                return [
                    'subject' => $title,
                    'label' => __('notifications.season_number_announced', ['number' => (string) $event->season], $locale),
                    'meta' => null,
                ];
            case MediaEventType::PersonNewTitle:
                return ['subject' => $title, 'label' => __('notifications.new_title_with_name', ['name' => $event->personName ?? ''], $locale), 'meta' => $date];
            case MediaEventType::CompanyNewTitle:
                return ['subject' => $title, 'label' => __('notifications.new_title_from_name', ['name' => $event->companyName ?? ''], $locale), 'meta' => $date];
            case MediaEventType::CollectionNewItem:
                if ($event->listsSeveral()) {
                    return ['subject' => $this->title($event, $locale), 'label' => '', 'meta' => $this->plainDetail($event, $locale)];
                }

                return [
                    'subject' => $title,
                    'label' => __('notifications.new_part_of_collection', ['collection' => $event->collectionTitle ?? ''], $locale),
                    'meta' => $date,
                ];
            case MediaEventType::TitlesRemoved:
                return ['subject' => $this->title($event, $locale), 'label' => '', 'meta' => $this->plainDetail($event, $locale)];
            default:
                return ['subject' => $title, 'label' => '', 'meta' => $this->plainDetail($event, $locale)];
        }
    }

    /** The title type's palette colour as a Discord embed int; a part takes its root's. */
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
     * Localized broadcast time-of-day in the viewer's timezone ("14:00" /
     * "2:00 PM"), when the position carries a real airing instant.
     */
    public function airTime(MediaEventPayload $event, string $locale, string $timezone): ?string
    {
        $instant = Payload::string($event->media, 'releaseTime');

        if ($instant === null) {
            return null;
        }

        return Carbon::parse($instant)->setTimezone($timezone)->settings(['locale' => $locale])->isoFormat('LT');
    }

    private function collectionGrew(MediaEventPayload $event, string $locale): string
    {
        $collection = ['collection' => $event->collectionTitle ?? ''];

        return $event->listsSeveral()
            ? trans_choice('notifications.count_new_parts_of_collection', count($event->titles()), $collection, $locale)
            : __('notifications.new_part_of_collection', $collection, $locale);
    }

    /**
     * The first few titles, then how many more the notification's page lists.
     *
     * @return list<string>
     */
    private function titlesPreview(MediaEventPayload $event, string $locale): array
    {
        $titles = $event->titles();
        $named = array_slice($titles, 0, self::PREVIEW_TITLES);
        $rest = count($titles) - count($named);

        return $rest > 0 ? [...$named, trans_choice('notifications.count_more', $rest, [], $locale)] : $named;
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

    /**
     * Long localized date, as the client's `formatReleaseDate` writes it: the
     * day when it is known, else only as much as is ("March 2028", "2028").
     */
    private function date(?string $date, DatePrecision $precision, string $locale): ?string
    {
        if ($date === null) {
            return null;
        }

        $day = Carbon::parse($date)->settings(['locale' => $locale]);

        return match ($precision) {
            DatePrecision::Day => $day->isoFormat('LL'),
            DatePrecision::Month => $day->isoFormat('MMMM YYYY'),
            DatePrecision::Quarter => __('common.date_quarter', ['quarter' => (string) $day->quarter, 'year' => (string) $day->year], $locale),
            DatePrecision::Year => (string) $day->year,
        };
    }
}
