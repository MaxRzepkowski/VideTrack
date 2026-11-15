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
