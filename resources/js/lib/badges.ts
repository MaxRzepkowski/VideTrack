import { episodeCode } from '@/lib/format';
import type { BookKind, EpisodeBadge as EpisodeMilestone, GameKind, UserMediaState } from '@/types/generated';
import type { MediaSummary } from '@/types/media';
import { trans } from 'laravel-vue-i18n';

/**
 * Card corner code of a hierarchical leaf: "S02E09" for episodes; null for
 * everything else.
 */
export function leafCode(media: MediaSummary): string | null {
    if (media.type === 'episode') {
        return episodeCode(Number(media.extra.seasonNumber ?? 0), media.number ?? 0);
    }

    return null;
}

/** The show a season or an episode belongs to; null for anything else. */
export function showTitleOf(media: MediaSummary): string | null {
    return media.type === 'episode' || media.type === 'season' ? (media.extra.showTitle ?? null) : null;
}

/**
 * Does the narrow card's marker strip (`MediaStateMarkers`) stand in the
 * poster's bottom-left corner? A card that draws a badge there reads this to
 * step out of its way, so the rule lives here rather than in each of them.
 */
export function hasStateMarkers(state: UserMediaState): boolean {
    return state.favorite || state.listIds.length > 0;
}

export interface EpisodeBadge {
    label: string;
    color: string;
}

/** How a premiere or finale chip reads and looks; the server names the milestone. */
export function episodeBadgeView(milestone: EpisodeMilestone): EpisodeBadge {
    switch (milestone) {
        case 'series_premiere':
            return { label: trans('common.series_premiere'), color: 'var(--color-episode-premiere)' };
        case 'season_premiere':
            return { label: trans('common.season_premiere'), color: 'var(--color-episode-premiere)' };
        case 'mid_season_finale':
            return { label: trans('common.mid_season_finale'), color: 'var(--color-episode-finale)' };
        case 'season_finale':
            return { label: trans('common.season_finale'), color: 'var(--color-episode-finale)' };
        case 'series_finale':
            return { label: trans('common.series_finale'), color: 'var(--color-episode-finale)' };
    }
}

/** Premiere/finale chip of an episode summary; the server names the milestone. */
export function episodeBadge(media: MediaSummary): EpisodeBadge | null {
    const milestone = media.extra.episodeBadge;

    return milestone ? episodeBadgeView(milestone) : null;
}

// The server's game kinds → their badge label; one for every kind it names.
// Thunks keep `trans()` a literal call (i18n extraction) yet re-run on locale change.
const GAME_TYPE_LABELS: Record<GameKind, () => string> = {
    dlc: () => trans('media.dlc'),
    expansion: () => trans('media.expansion'),
    bundle: () => trans('media.bundle'),
    standalone_expansion: () => trans('media.standalone_expansion'),
    mod: () => trans('media.mod'),
    episode: () => trans('media.episode'),
    season: () => trans('media.season'),
    remake: () => trans('media.remake'),
    remaster: () => trans('media.remaster'),
    expanded: () => trans('media.expanded_game'),
    port: () => trans('media.port'),
    fork: () => trans('media.fork'),
    pack: () => trans('media.pack'),
    update: () => trans('media.update'),
};

/** Subtype chip for a non-main game (DLC, Bundle, Remake…); null otherwise. */
export function gameTypeBadge(media: MediaSummary): EpisodeBadge | null {
    const kind = media.extra.gameType;

    return kind ? { label: GAME_TYPE_LABELS[kind](), color: 'var(--color-media-game)' } : null;
}

// The server's book kinds → their label; a plain book has none.
const BOOK_KIND_LABELS: Record<BookKind, () => string> = {
    novella: () => trans('media.book_kind_novella'),
    short_story: () => trans('media.book_kind_short_story'),
    graphic_novel: () => trans('media.book_kind_graphic_novel'),
    fan_fiction: () => trans('media.book_kind_fan_fiction'),
    research: () => trans('media.book_kind_research'),
    poetry: () => trans('media.book_kind_poetry'),
    anthology: () => trans('media.book_kind_anthology'),
    web_novel: () => trans('media.book_kind_web_novel'),
    light_novel: () => trans('media.book_kind_light_novel'),
};

/** A book kind in the reader's language. */
export function bookKindLabel(kind: BookKind): string {
    return BOOK_KIND_LABELS[kind]();
}

/** Kind chip for a book that is not a plain book (a novella, a graphic novel…); null otherwise. */
export function bookKindBadge(media: MediaSummary): EpisodeBadge | null {
    const kind = media.extra.bookKind;

    return kind ? { label: bookKindLabel(kind), color: 'var(--color-media-book)' } : null;
}

/** The corner chip a card shows: an episode premiere/finale, else a game subtype or a book kind. */
export function cardBadge(media: MediaSummary): EpisodeBadge | null {
    return episodeBadge(media) ?? gameTypeBadge(media) ?? bookKindBadge(media);
}
