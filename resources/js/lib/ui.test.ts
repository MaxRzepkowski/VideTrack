import { navWordState, POSTER_GRID_STEPS, posterGridClass, rowTint, SELECT_CLASS, SELECT_CLASS_ON_CARD } from '@/lib/ui';
import { describe, expect, it } from 'vitest';

describe('ui classes', () => {
    it("tints a row with its group's tokens, or the danger colour", () => {
        expect(rowTint('discover')).toEqual({
            '--tint': 'var(--color-nav-discover)',
            '--tint-fill': 'var(--color-nav-discover-fill)',
            '--tint-ink': 'var(--color-nav-discover-ink)',
        });
        expect(rowTint('danger')['--tint']).toBe('var(--color-danger)');
        expect(rowTint('remove')['--tint-ink']).toBe('var(--color-on-remove)');
    });

    it('lights the menu word of the page you are on, and only it', () => {
        expect(navWordState(true)).toBe('bg-surface-raised text-ink');
        expect(navWordState(false)).not.toContain('bg-surface-raised');
        expect(navWordState(false)).toContain('hover:');
    });

    it('raises the select one step on a card and changes nothing else', () => {
        expect(SELECT_CLASS_ON_CARD).toContain('bg-surface-raised');
        expect(SELECT_CLASS_ON_CARD.replace('bg-surface-raised', 'bg-surface')).toBe(SELECT_CLASS);
    });

    it('builds the poster grid from its column steps', () => {
        expect(posterGridClass()).toBe('xs:gap-5 grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5');
        expect(posterGridClass(4)).toBe('xs:gap-5 grid gap-3 grid-cols-2 sm:grid-cols-3 xl:grid-cols-4');
    });

    it('keeps the narrower grid a column behind from lg up, never ahead', () => {
        const widest = (steps: (typeof POSTER_GRID_STEPS)[4]) => Math.max(...steps.map((step) => step.columns));

        expect(widest(POSTER_GRID_STEPS[4])).toBe(widest(POSTER_GRID_STEPS[5]) - 1);
    });
});
