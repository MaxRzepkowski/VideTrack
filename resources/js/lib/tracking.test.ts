import { nextFollowMode, trackedBecause } from '@/lib/tracking';
import { describe, expect, it } from 'vitest';

describe('trackedBecause', () => {
    it('names the row a title is tracked through, and stays quiet for bells and untouched titles', () => {
        expect(trackedBecause('on_hold')).toBe('Following (on hold)');
        expect(trackedBecause('dropped')).toBe('Following (dropped)');
        expect(trackedBecause('backlog')).toBe('Following (backlog)');
        expect(trackedBecause('activity')).toBe('Following (watching)');
        expect(trackedBecause('followed')).toBeNull();
        expect(trackedBecause('creator')).toBeNull();
        expect(trackedBecause(null)).toBeNull();
    });
});

describe('nextFollowMode', () => {
    it.each([
        ['nothing tracks the title', { follow: null, followSource: null, followVia: null }, 'follow'],
        ['the user rang it', { follow: 'follow', followSource: 'followed', followVia: null }, null],
        ['a creator rang it', { follow: 'follow', followSource: 'creator', followVia: 'Keanu Reeves' }, 'mute'],
        ['the matrix tracks it', { follow: null, followSource: 'activity', followVia: null }, 'mute'],
        ['a status outranks a rung bell', { follow: 'follow', followSource: 'dropped', followVia: null }, 'mute'],
        ['it is muted', { follow: 'mute', followSource: null, followVia: null }, null],
    ] as const)('when %s, one click moves it on', (_, state, next) => {
        expect(nextFollowMode(state)).toBe(next);
    });
});
