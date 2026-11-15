import type { CalendarEntryGroup } from '@/lib/calendar';
import { episodeCode, formatCalendarDate, formatTime, formatTimestampDate } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { eventHours } from '@/lib/liveEvents';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { batchHref, releaseFacts, releaseStatusChip } from '@/lib/releases';
import { mediaUrl } from '@/lib/urls';
import type { CalendarEvent, EventEntry } from '@/types/generated';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Radio } from 'lucide-vue-next';
import type { Component } from 'vue';

/**
 * What the calendar's hover preview shows of whatever was pointed at: a
 * release (or a show's batch of the day) and an event read the same box, so
 * each says it in these terms and the box never learns which one it holds.
 */
export interface CalendarPreview {
    href: string;
    title: string;
    badge: { type: string; label?: string; icon?: Component };
    image: {
        src: string | null;
        srcset?: string;
        /** A release's poster stands beside the text; an event's art is 16:9 and spans the box above it. */
        shape: 'poster' | 'wide';
        /** The colour standing in for a missing image. */
        fallback: string;
    };
    context: string | null;
    date: string;
    time: string | null;
    note: string | null;
    /** An event's span: the box marks it live while it is on the air. */
    airs?: Pick<EventEntry, 'startsAt' | 'endsAt'>;
}

/**
 * A release, or a show's episodes of the day. Episodes own no portrait art, so
 * their show's poster comes first; a backdrop is only the last resort, keeping
 * the preview poster-shaped even when a provider has no cover.
 */
export function releasePreview(date: string, group: CalendarEntryGroup): CalendarPreview {
    const [entry] = group.entries;
    const media = entry.media;
    const type = entry.showTitle !== null ? 'show' : media.type;
    const poster = media.extra.showPoster ?? media.posterPath ?? media.backdropPath;

    let context: string | null;

    if (group.entries.length > 1) {
        context = transChoice('common.count_episode', group.entries.length, { count: String(group.entries.length) });
    } else if (entry.showTitle !== null) {
        context = `${episodeCode(Number(media.extra.seasonNumber ?? 0), media.number ?? 0)} – ${media.title}`;
    } else {
        context = releaseFacts(media).join(' – ');
    }

    return {
        href: group.entries.length === 1 ? mediaUrl(media) : batchHref(group.entries),
        title: entry.showTitle ?? media.title,
        badge: { type },
        image: {
            src: mediaImage(poster, 'w342'),
            srcset: mediaImageSrcSet(poster, [185, 342]),
            shape: 'poster',
            fallback: media.extra.coverColor ?? mediaTypeMeta(type).color,
        },
        context,
        // A calendar key rather than an instant: UTC keeps it on the same square of the month in every zone.
        date: formatCalendarDate(date) ?? date,
        time: formatTime(media.releaseTime),
        note: media.extra.releaseNote ?? releaseStatusChip(media),
    };
}

/** An event: worn in the colour of the medium it shows, as among the countdowns, its hours in the viewer's zone. */
export function eventPreview({ event, mediumType }: CalendarEvent): CalendarPreview {
    return {
        href: route('events.show', { provider: event.provider, ref: event.ref }),
        title: event.name,
        badge: { type: mediumType, label: trans('calendar.event'), icon: Radio },
        image: { src: event.logoPath, shape: 'wide', fallback: mediaTypeMeta(mediumType).color },
        context: null,
        date: formatTimestampDate(event.startsAt) ?? '',
        time: eventHours(event),
        note: null,
        airs: event,
    };
}
