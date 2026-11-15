import { trans } from 'laravel-vue-i18n';

/** Community rating (0–10) as the percentage badge used across the app. */
export function ratingPercent(rating: number | null): string | null {
    return rating === null ? null : `${Math.round(rating * 10)}%`;
}

/**
 * A band of the shared rating colour scale (seriesgraph-style green↔red). The
 * printed value in every cell plus the labelled legend keep it readable under
 * CVD. Used by both the episode-quality grid and the collection-ratings grid.
 */
export interface RatingTier {
    label: () => string;
    min: number;
    /** `var(--color-tier-*)`. */
    color: string;
    /** Ink of the value printed on the tier colour. */
    ink: string;
}

const FLOOR_TIER: RatingTier = { label: () => trans('media.garbage'), min: 0, color: 'var(--color-tier-garbage)', ink: 'var(--color-on-photo)' };

export const RATING_TIERS: RatingTier[] = [
    { label: () => trans('media.absolute_cinema'), min: 9.3, color: 'var(--color-tier-absolute)', ink: 'var(--color-on-photo)' },
    { label: () => trans('media.awesome'), min: 8.8, color: 'var(--color-tier-awesome)', ink: 'var(--color-on-photo)' },
    { label: () => trans('media.great'), min: 8, color: 'var(--color-tier-great)', ink: 'var(--color-surface-base)' },
    { label: () => trans('media.good'), min: 7.5, color: 'var(--color-tier-good)', ink: 'var(--color-surface-base)' },
    { label: () => trans('media.regular'), min: 6.5, color: 'var(--color-tier-regular)', ink: 'var(--color-surface-base)' },
    { label: () => trans('media.bad'), min: 5.5, color: 'var(--color-tier-bad)', ink: 'var(--color-on-photo)' },
    FLOOR_TIER,
];

/** The first tier whose `min` the rating meets (Garbage is the floor). */
export function ratingTier(rating: number): RatingTier {
    return RATING_TIERS.find((tier) => rating >= tier.min) ?? FLOOR_TIER;
}
