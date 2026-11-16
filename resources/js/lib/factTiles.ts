import type { RankingKind, RankingPosition } from '@/types/generated';
import { Trophy, Users, type LucideIcon } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';

/** One headline fact of a title as a tile: an icon, what it is and its value. */
export interface FactTile {
    key: string;
    icon: LucideIcon;
    label: string;
    value: string;
}

// Thunks, so every `trans()` stays a literal call the key checks can see.
const RANKINGS: Record<RankingKind, { icon: LucideIcon; label: () => string }> = {
    score: { icon: Trophy, label: () => trans('media.ranked_by_score') },
    popularity: { icon: Users, label: () => trans('media.ranked_by_popularity') },
};

/** A title's places in its provider's rankings as tiles, each a position ("#1"). */
export function rankingTiles(rankings: RankingPosition[]): FactTile[] {
    return rankings.map((ranking) => ({
        key: ranking.kind,
        icon: RANKINGS[ranking.kind].icon,
        label: RANKINGS[ranking.kind].label(),
        value: trans('media.rank_position', { position: String(ranking.position) }),
    }));
}
