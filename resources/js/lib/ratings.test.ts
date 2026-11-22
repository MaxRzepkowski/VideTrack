import { RATING_MAX, RATING_MIN, RATING_TIERS, ratingAt, ratingPercent, ratingScore, ratingTier } from '@/lib/ratings';
import { describe, expect, it } from 'vitest';

describe('ratingPercent', () => {
    it('shows a 0–10 rating as a rounded percentage', () => {
        expect(ratingPercent(7.46)).toBe('75%');
        expect(ratingPercent(0)).toBe('0%');
        expect(ratingPercent(10)).toBe('100%');
    });

    it('shows no badge without a rating', () => {
        expect(ratingPercent(null)).toBeNull();
    });
});

describe('ratingTier', () => {
    it('picks the first tier whose floor the rating meets', () => {
        expect(ratingTier(9.5).label()).toBe('Absolute Cinema');
        expect(ratingTier(9.3).label()).toBe('Absolute Cinema');
        expect(ratingTier(9.29).label()).toBe('Awesome');
        expect(ratingTier(7.5).label()).toBe('Good');
        expect(ratingTier(7.49).label()).toBe('Regular');
    });

    it('lands everything below the scale on the floor tier', () => {
        expect(ratingTier(0)).toBe(RATING_TIERS[RATING_TIERS.length - 1]);
        expect(ratingTier(-1)).toBe(RATING_TIERS[RATING_TIERS.length - 1]);
    });

    it('orders the tiers from the highest floor down', () => {
        const floors = RATING_TIERS.map((tier) => tier.min);

        expect(floors).toEqual([...floors].sort((a, b) => b - a));
    });
});

describe('ratingScore', () => {
    it('reads a rating beside the scale', () => {
        expect(ratingScore(7)).toBe(`7/${RATING_MAX}`);
    });
});

describe('ratingAt', () => {
    it('gives each star an equal slice of the row, holding the ends past them', () => {
        const slice = 30;
        const width = slice * (RATING_MAX - RATING_MIN + 1);

        expect(ratingAt(0, width)).toBe(RATING_MIN);
        expect(ratingAt(slice - 1, width)).toBe(RATING_MIN);
        expect(ratingAt(slice, width)).toBe(RATING_MIN + 1);
        expect(ratingAt(width - 1, width)).toBe(RATING_MAX);
        expect(ratingAt(-40, width)).toBe(RATING_MIN);
        expect(ratingAt(width + 200, width)).toBe(RATING_MAX);
    });
});
