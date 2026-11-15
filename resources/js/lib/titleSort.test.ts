import { byRating, byRelease, byTitle } from '@/lib/titleSort';
import { mediaSummary } from '@/test/factories';
import { describe, expect, it } from 'vitest';

const titles = [
    mediaSummary({ title: 'Beta', communityRating: 9, releaseDate: '2024-05-01' }),
    mediaSummary({ title: 'Alpha', communityRating: null, releaseDate: null }),
    mediaSummary({ title: 'Gamma', communityRating: 6, releaseDate: '2020-01-01' }),
];

const order = (compare: (a: (typeof titles)[number], b: (typeof titles)[number]) => number) => [...titles].sort(compare).map((t) => t.title);

describe('titleSort', () => {
    it('orders by rating, the unrated lowest', () => {
        expect(order(byRating)).toEqual(['Alpha', 'Gamma', 'Beta']);
    });

    it('orders by title', () => {
        expect(order(byTitle)).toEqual(['Alpha', 'Beta', 'Gamma']);
    });

    it('orders by release date, the undated first', () => {
        expect(order(byRelease)).toEqual(['Alpha', 'Gamma', 'Beta']);
    });
});
