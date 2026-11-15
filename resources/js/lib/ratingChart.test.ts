import { cellAnchor, pointAnchor, ratingScale } from '@/lib/ratingChart';
import { describe, expect, it } from 'vitest';

describe('ratingScale', () => {
    it('fits the axis around the ratings, within 0 to 10', () => {
        const scale = ratingScale([7.2, 8.9], 10, 100);

        expect([scale.yMin, scale.yMax]).toEqual([6, 10]);
        expect(scale.grid).toEqual([6, 7, 8, 9, 10]);
        expect(scale.yOf(10)).toBe(10);
        expect(scale.yOf(6)).toBe(110);
    });
});

describe('tooltip anchors', () => {
    it('opens below a mark near the top and hangs inwards at either edge', () => {
        expect(pointAnchor(50, 100, 1000)).toEqual({ x: 50, y: 112, below: true, align: 'left' });
        expect(pointAnchor(500, 200, 1000)).toEqual({ x: 500, y: 190, below: false, align: 'center' });
        expect(pointAnchor(950, 200, 1000).align).toBe('right');
    });

    it('measures a heatmap cell against its plot', () => {
        const cell = new DOMRect(110, 300, 40, 20);
        const plot = new DOMRect(10, 0, 800, 600);

        expect(cellAnchor(cell, plot, 800)).toEqual({ x: 120, y: 296, below: false, align: 'left' });
    });
});
