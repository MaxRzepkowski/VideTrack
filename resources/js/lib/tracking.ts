import type { FollowMode, UserMediaState } from '@/types/generated';
import type { TrackingSource } from '@/types/media';
import { trans } from 'laravel-vue-i18n';

/**
 * What the bell says about a title the tracking matrix covers on its own —
 * the row the server put it in. Null for a title only a bell tracks (the bell
 * then speaks for itself) and for one nothing tracks.
 */
export function trackedBecause(source: TrackingSource | null): string | null {
    switch (source) {
        case 'on_hold':
            return trans('media.following_on_hold');
        case 'dropped':
            return trans('media.following_dropped');
        case 'backlog':
            return trans('media.following_backlog');
        case 'activity':
            return trans('media.following_watching');
        default:
            return null;
    }
}

/**
 * Where one click on a title's bell takes it: off → follow, auto-followed →
 * mute, forced or muted → back to whatever the matrix says. A bell a creator
 * rang cannot be plainly taken back (the creator's sync would ring it again),
 * so its exit is muting.
 */
export function nextFollowMode(state: Pick<UserMediaState, 'follow' | 'followSource' | 'followVia'>): FollowMode | null {
    if (state.follow === 'mute') {
        return null;
    }

    if (trackedBecause(state.followSource) !== null) {
        return 'mute';
    }

    if (state.follow === 'follow') {
        return state.followVia ? 'mute' : null;
    }

    return 'follow';
}

/**
 * The groups of titles the tracking settings route to the calendar, the
 * countdowns and the notifications, each with the preference that switches it
 * for each of the three.
 */
export function trackingSources() {
    return [
        {
            key: 'active',
            // The row holds anything with a play on it. A series is "in the
            // middle", a film is simply seen — one word has to cover both, and
            // the app already calls that place your history.
            label: trans('settings.in_your_history'),
            description: trans('settings.series_and_games_you_are_in'),
            calendar: 'calendar_active',
            countdown: 'countdown_active',
            notifications: 'notifications_active',
        },
        {
            key: 'watchlist',
            label: trans('common.backlog'),
            description: trans('settings.titles_you_plan_to_get_to'),
            calendar: 'calendar_watchlist',
            countdown: 'countdown_watchlist',
            notifications: 'notifications_watchlist',
        },
        {
            key: 'on_hold',
            label: trans('settings.on_hold_titles'),
            description: trans('settings.paused_titles_a_fresh_season_is'),
            calendar: 'calendar_on_hold',
            countdown: 'countdown_on_hold',
            notifications: 'notifications_on_hold',
        },
        {
            key: 'dropped',
            label: trans('settings.dropped_titles'),
            description: trans('settings.you_gave_up_on_it_but'),
            calendar: 'calendar_dropped',
            countdown: 'countdown_dropped',
            notifications: 'notifications_dropped',
        },
        {
            key: 'followed',
            label: trans('common.followed_manually'),
            description: trans('settings.titles_you_rang_the_bell_on'),
            calendar: 'calendar_followed',
            countdown: 'countdown_followed',
            notifications: 'notifications_followed',
        },
        {
            key: 'creators',
            label: trans('settings.new_titles_from_followed_creators'),
            description: trans('settings.titles_the_bell_picked_up_from'),
            calendar: 'calendar_creators',
            countdown: 'countdown_creators',
            notifications: 'notifications_creators',
        },
    ] as const;
}

/** The tabs of the tracking settings, in their order; `?tab=` opens one from a link. */
export const TRACKING_TABS = ['views', 'notifications', 'creators'] as const;

export type TrackingTab = (typeof TRACKING_TABS)[number];
