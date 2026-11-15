import CollectionFacts from '@/components/lists/CollectionFacts.vue';
import DetailsModal from '@/components/media/DetailsModal.vue';
import type { CollectionOverview } from '@/types/generated';
import type { DetailRow } from '@/types/media';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const overview = (overrides: Partial<CollectionOverview> = {}): CollectionOverview => ({
    titleCount: 4,
    releasedCount: 4,
    upcomingCount: 0,
    budget: 363_000_000,
    budgetCounted: 4,
    revenue: 1_788_000_000,
    revenueCounted: 4,
    totalRuntime: 548,
    studios: [],
    productionCountries: ['US', 'AU'],
    originalLanguages: ['en'],
    certifications: ['R', 'PG-13'],
    keywords: [],
    cast: [],
    crew: [],
    ...overrides,
});

function rows(facts: CollectionOverview, partType: string | null = 'movie'): Record<string, string | null> {
    const wrapper = mount(CollectionFacts, {
        props: { overview: facts, collection: { provider: 'tmdb', ref: '2344' }, partType },
        global: { stubs: { DetailsModal: true } },
    });

    return Object.fromEntries((wrapper.findComponent(DetailsModal).props('rows') as DetailRow[]).map((row) => [row.label, row.value]));
}

describe('the facts of a collection', () => {
    it('sums the saga and gathers what its movies share', () => {
        expect(rows(overview())).toEqual({
            'Number of movies': '4',
            'Total budget': '$363,000,000',
            'Total box office': '$1,788,000,000',
            'Total runtime': '9h 8min',
            'Production countries': 'United States, Australia',
            'Original language': 'EN',
            Certification: 'R, PG-13',
        });
    });

    it('tells released movies from announced ones', () => {
        expect(rows(overview({ releasedCount: 3, upcomingCount: 1 }))['Number of movies']).toBe('3 released, 1 announced');
    });

    it('counts a series in what its parts are', () => {
        const facts = overview({ budget: null, budgetCounted: 0, revenue: null, revenueCounted: 0 });

        expect(rows(facts, 'game')['Number of games']).toBe('4');
        expect(rows(facts, null)['Number of titles']).toBe('4');
    });

    it('says how many movies an incomplete sum covers', () => {
        expect(rows(overview({ budgetCounted: 3 }))['Total budget']).toBe('$363,000,000 (from 3 of 4 movies)');
    });

    it('leaves out what no movie said', () => {
        const facts = rows(overview({ budget: null, budgetCounted: 0, productionCountries: [], certifications: [] }));

        expect([facts['Total budget'], facts['Production countries'], facts.Certification]).toEqual([null, null, null]);
    });
});
