import { COUNTRY_THRESHOLDS, countryShade, GAP, MAP_GROUND, packBubbles, RADIUS_MAX } from '@/lib/origins';
import type { MakerTally } from '@/types/generated';
import { describe, expect, it } from 'vitest';

const maker = (name: string, titles: number): MakerTally => ({
    creator: { name, kind: 'company', ref: null, provider: null, logoPath: null },
    titles,
    best: [],
});

describe('the map', () => {
    it('shades a country by fixed steps of its titles, from a step above the sea', () => {
        const [first, second, third] = COUNTRY_THRESHOLDS;

        expect(countryShade(0)).toBe(MAP_GROUND.none);
        expect(countryShade(first)).toBe('var(--color-scale-2)');
        expect(countryShade(second - 1)).toBe('var(--color-scale-2)');
        expect(countryShade(second)).toBe('var(--color-scale-3)');
        expect(countryShade(third * 25)).toBe('var(--color-scale-4)');
    });
});

describe('the bubbles', () => {
    const makers = [maker('A', 16), maker('B', 4), ...Array.from({ length: 200 }, (_, i) => maker(`C${i}`, 1))];

    it('packs every maker into the box, the area following the count, no two touching', () => {
        const box = { width: 600, height: 300 };
        const { bubbles, width, height } = packBubbles(makers, box);

        expect(bubbles).toHaveLength(makers.length);
        expect(width).toBeLessThanOrEqual(box.width);
        expect(height).toBeLessThanOrEqual(box.height);
        expect(bubbles[0]!.r / bubbles[1]!.r).toBeCloseTo(2);

        for (const bubble of bubbles) {
            expect(bubble.x - bubble.r).toBeGreaterThan(0);
            expect(bubble.y - bubble.r).toBeGreaterThan(0);
            expect(bubble.x + bubble.r).toBeLessThan(width);
            expect(bubble.y + bubble.r).toBeLessThan(height);
        }

        for (const [i, a] of bubbles.entries()) {
            for (const b of bubbles.slice(i + 1)) {
                expect(Math.hypot(a.x - b.x, a.y - b.y) - a.r - b.r).toBeGreaterThan(GAP - 1e-6);
            }
        }
    });

    it('stops growing at the largest size when there is room to spare', () => {
        const { bubbles } = packBubbles([maker('A', 9), maker('B', 1)], { width: 5000, height: 5000 });

        expect(bubbles[0]!.r).toBeCloseTo(RADIUS_MAX);
        expect(bubbles[1]!.r).toBeCloseTo(RADIUS_MAX / 3);
    });

    it('packs nothing into an empty box', () => {
        expect(packBubbles([], { width: 100, height: 100 })).toEqual({ bubbles: [], width: 0, height: 0 });
    });
});
