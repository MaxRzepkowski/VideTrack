<?php

namespace App\Notifications\Channels;

use App\Catalog\MediaTypeRegistry;
use App\Models\User;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Notifications\Channels\Data\DigestLine;
use App\Notifications\Channels\Data\DigestSection;
use App\Notifications\MediaEventPayload;
use App\Notifications\NotificationCopy;
use App\Support\Payload;
use App\Support\PublicUrl;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * What a channel is handed, in its owner's language and timezone: one
 * notification as a message, a day of them as a digest, or the test the
 * settings page sends. What each notification says is NotificationCopy's;
 * this adds only the digest's frame (heading, sections, closing).
 */
final readonly class ChannelMessages
{
    /** The section for anything no registered type names. */
    private const OTHER = '';

    private const OTHER_EMOJI = '✨';

    public function __construct(
        private NotificationCopy $copy,
        private MediaTypeRegistry $types,
    ) {}

    public function message(MediaEventPayload $event, User $user): ChannelMessage
    {
        $locale = $user->preferredLocale();

        return new ChannelMessage(
            title: $this->copy->title($event, $locale),
            detail: $this->copy->timedDetail($event, $locale, $user->preferences->effectiveTimezone()),
            url: $this->copy->url($event),
            imageUrl: $this->copy->imageUrl($event),
            color: $this->copy->color($event),
            writtenAt: now()->toIso8601String(),
        );
    }

    /** The message "Send a test" delivers: it leads back to the channel's settings. */
    public function test(User $user): ChannelMessage
    {
        return new ChannelMessage(
            title: __('settings.test_notification_this_channel_works', [], $user->preferredLocale()),
            detail: null,
            url: PublicUrl::to(route('settings.channels.index', absolute: false)),
            imageUrl: null,
            color: NotificationCopy::BRAND_COLOR,
            writtenAt: now()->toIso8601String(),
        );
    }

    /**
     * The notifications as one digest: a section per root media type, in the
     * registry's order and named by the type's own registration, a season or
     * an episode under its show. Null when there is nothing to tell.
     *
     * @param  Collection<int, DatabaseNotification>  $notifications  in the order they happened
     */
    public function digest(Collection $notifications, User $user): ?ChannelDigest
    {
        $locale = $user->preferredLocale();
        $timezone = $user->preferences->effectiveTimezone();
        $lines = [];

        foreach ($notifications as $notification) {
            $event = MediaEventPayload::fromStored($notification->data);
            $parts = $this->copy->digestParts($event, $locale, $timezone);
            $lines[$this->sectionOf(Payload::string($event->media, 'type'))][] = new DigestLine(
                subject: $parts['subject'],
                url: $this->copy->url($event),
                label: $parts['label'],
                meta: $parts['meta'] === '' ? null : $parts['meta'],
            );
        }

        $sections = [];

        foreach ($this->headings($locale) as $section => [$heading, $emoji]) {
            if (isset($lines[$section])) {
                $sections[] = new DigestSection($heading, $emoji, $lines[$section]);
            }
        }

        if ($sections === []) {
            return null;
        }

        return new ChannelDigest(
            heading: __('notifications.your_daily_digest', [], $locale),
            sections: $sections,
            closing: __('notifications.enjoy_your_media', [], $locale),
        );
    }

    /** The root type a title's notification files under, or the catch-all section. */
    private function sectionOf(?string $type): string
    {
        if ($type === null || ! $this->types->has($type)) {
            return self::OTHER;
        }

        $lineage = $this->types->lineage($type);
        $root = end($lineage);

        return $root->parentType === null && $root->pluralLabel !== null ? $root->code : self::OTHER;
    }

    /**
     * Every section's heading and emoji, in the order the digest lists them.
     *
     * @return array<string, array{string, string|null}> section => [heading, emoji]
     */
    private function headings(string $locale): array
    {
        $headings = [];

        foreach ($this->types->all() as $definition) {
            if ($definition->parentType === null && $definition->pluralLabel !== null) {
                $headings[$definition->code] = [__($definition->pluralLabel, [], $locale), $definition->emoji];
            }
        }

        $headings[self::OTHER] = [__('notifications.other_titles', [], $locale), self::OTHER_EMOJI];

        return $headings;
    }
}
