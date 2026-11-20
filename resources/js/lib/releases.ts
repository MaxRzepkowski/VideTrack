import { formatNumber, todayDateKey } from '@/lib/format';
import { showRefOf } from '@/lib/hierarchy';
import { MEDIA_TYPE_META } from '@/lib/mediaTypes';
import type { EpisodeImage, NonEmpty } from '@/types';
import type { CalendarEntry, MediaSummary } from '@/types/media';
import { trans, transChoice } from 'laravel-vue-i18n';

/*
 * What a release card says about the release it announces — shared by the
 * calendar card, the calendar's day preview and the countdown card.
 */

/**
 * The facts under a title's name: what kind of release this is, and where. A
 * film can appear twice — its premiere and the day it left cinemas — so the
 * line has to say which of the two this one is, or the calendar looks like it
 * duplicated the title; landing on ONE day they arrive merged, and the line
 * names both. An episode is said by its code instead, which each card sets.
 * A game's DLC, remake or remaster names the game it belongs to.
 */
export function releaseFacts(media: MediaSummary): string[] {
    // A game's DLC or remake names its game instead: its badge says what kind of release it is.
    if (media.extra.relatedTo) {
        return [media.extra.relatedTo, media.extra.platform].filter((fact): fact is string => fact !== undefined && fact !== '');
    }

    const label = MEDIA_TYPE_META[media.type]?.release?.();

    switch (media.extra.releaseKind) {
        case 'home':
            return [trans('common.outside_cinemas')];
        case 'both':
            return [label, trans('common.outside_cinemas')].filter((fact): fact is string => fact !== undefined);
        default:
            return [label, media.extra.platform].filter((fact): fact is string => fact !== undefined && fact !== '');
    }
}

/** Release kinds (Early Access, Beta, Next-Gen Optimization Patch…) get their own chip; a plain release carries no status and needs none. */
export function releaseStatusChip(media: MediaSummary): string | null {
    return media.extra.releaseStatus || null;
}

/**
 * Creator-sourced tracking, compacted: several sponsors collapse to the first
 * name + a counter, the full list riding along for the tooltip.
 */
export function compactFollowedVia(media: MediaSummary): { compact: string; full: string } | null {
    const raw = media.extra.followedVia;

    if (typeof raw !== 'string' || raw === '') {
        return null;
    }

    const names = raw.split(', ');

    return { full: raw, compact: names.length > 1 ? `${names[0]} +${names.length - 1}` : raw };
}

/**
 * The landscape image of a release — a real backdrop only. An episode picks
 * its own still or its show's backdrop by preference, the other falling in
 * behind it; anything else uses its own backdrop.
 */
export function releaseBackdrop(entry: CalendarEntry, episodeImage: EpisodeImage): string | null {
    const still = entry.media.backdropPath;

    if (entry.showTitle === null) {
        return still;
    }

    const showBackdrop = entry.media.extra.showBackdrop ?? null;

    return episodeImage === 'still' ? (still ?? showBackdrop) : (showBackdrop ?? still);
}

/**
 * Where a day's batch of one show's episodes leads: the season they fill, or
 * the show when they straddle two.
 */
export function batchHref(entries: NonEmpty<CalendarEntry>): string {
    const showRef = showRefOf(entries[0].media.ref);
    const seasons = entries.map((item) => Number(item.media.extra.seasonNumber ?? 0));

    return Math.min(...seasons) === Math.max(...seasons)
        ? route('season.details', { ref: showRef, number: seasons[0] })
        : route('show.details', { ref: showRef });
}

/**
 * How many wait for a title still to come ("289 awaiting"); nothing once it is
 * out, when the count only says how it was anticipated.
 */
export function awaitedLabel(media: MediaSummary): string | null {
    const count = media.extra.awaitedBy;

    if (!count || (media.releaseDate !== null && media.releaseDate <= todayDateKey())) {
        return null;
    }

    return transChoice('discover.count_awaiting', count, { amount: formatNumber(count) });
}
