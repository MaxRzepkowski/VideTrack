import { shownStatus, shownStatusLabel, shownStatusVisual, STATUS_VISUALS } from '@/lib/status';
import { userState } from '@/test/factories';
import { describe, expect, it } from 'vitest';

describe('STATUS_VISUALS', () => {
    it('draws every library state with an icon and a colour token, never a literal colour', () => {
        for (const visual of Object.values(STATUS_VISUALS)) {
            expect(visual.icon).toBeTruthy();
            expect(visual.color).toMatch(/^var\(--color-/);
        }
    });
});

describe('shownStatus', () => {
    it('lets a status outrank being watched, and being watched outrank a container under way', () => {
        expect(shownStatus(userState({ watched: true, status: 'in_progress' }), false)).toBe('inProgress');
        expect(shownStatus(userState({ watched: true, status: 'dropped' }), false)).toBe('dropped');
        expect(shownStatus(userState({ watched: true, watchedEpisodes: 3 }), true)).toBe('watched');
        expect(shownStatus(userState({ watchedEpisodes: 3 }), true)).toBe('underWay');
    });

    it('shows nothing for a title in none of them, nor episodes counted on a playable', () => {
        expect(shownStatus(userState(), false)).toBeNull();
        expect(shownStatus(userState({ watchedEpisodes: 3 }), false)).toBeNull();
    });

    it('draws a container under way as in progress', () => {
        expect(shownStatusVisual('underWay')).toBe(STATUS_VISUALS.inProgress);
        expect(shownStatusVisual('onHold')).toBe(STATUS_VISUALS.onHold);
    });
});

describe('shownStatusLabel', () => {
    it('names each shown state plainly, a container under way as in progress', () => {
        expect(shownStatusLabel('watched')).toBe('Completed');
        expect(shownStatusLabel('underWay')).toBe(shownStatusLabel('inProgress'));
        expect(shownStatusLabel('onHold')).toBe('On hold');
    });
});
