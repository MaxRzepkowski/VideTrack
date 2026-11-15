import type { UserMediaState } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';
import { CircleCheck, CircleOff, CirclePlay, ListTodo, Pause, type LucideIcon } from 'lucide-vue-next';

/** How one library state looks wherever it is shown: its icon and its colour token. */
export interface StatusVisual {
    icon: LucideIcon;
    color: string;
}

/**
 * The library states as every surface draws them (the card's action bar, the
 * title page's status card). Surfaces keep their own layout; the look of a
 * state is decided here once.
 */
export const STATUS_VISUALS = {
    dropped: { icon: CircleOff, color: 'var(--color-status-dropped)' },
    onHold: { icon: Pause, color: 'var(--color-status-onhold)' },
    inProgress: { icon: CirclePlay, color: 'var(--color-status-progress)' },
    watched: { icon: CircleCheck, color: 'var(--color-action-watched)' },
    backlog: { icon: ListTodo, color: 'var(--color-action-watchlist)' },
} as const satisfies Record<string, StatusVisual>;

/** The state a title shows, of the ones a surface draws. */
export type ShownStatus = 'dropped' | 'onHold' | 'inProgress' | 'watched' | 'underWay';

/**
 * Which state a title shows, in the one order every surface reads it: a
 * status outranks being watched — a finished game started again is in
 * progress — and being watched outranks a container under way. Null for a
 * title in none of them.
 *
 * @param container  a show or a season: under way once an episode is watched
 */
export function shownStatus(state: Pick<UserMediaState, 'status' | 'watched' | 'watchedEpisodes'>, container: boolean): ShownStatus | null {
    switch (state.status) {
        case 'dropped':
            return 'dropped';
        case 'on_hold':
            return 'onHold';
        case 'in_progress':
            return 'inProgress';
    }

    if (state.watched) {
        return 'watched';
    }

    return container && (state.watchedEpisodes ?? 0) > 0 ? 'underWay' : null;
}

/** How a shown state looks; a container under way wears the in-progress look. */
export function shownStatusVisual(status: ShownStatus): StatusVisual {
    return status === 'underWay' ? STATUS_VISUALS.inProgress : STATUS_VISUALS[status];
}

/**
 * A shown state's plain name ("Completed", "On hold"), where a sentence
 * states it as a fact; a surface naming the act for its type (watched,
 * read, played) keeps its own words.
 */
export function shownStatusLabel(status: ShownStatus): string {
    switch (status) {
        case 'watched':
            return trans('common.completed');
        case 'inProgress':
        case 'underWay':
            return trans('common.in_progress');
        case 'onHold':
            return trans('common.on_hold');
        case 'dropped':
            return trans('common.dropped');
    }
}
