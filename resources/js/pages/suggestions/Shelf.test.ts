import MediaCard from '@/components/media/MediaCard.vue';
import SortControl from '@/components/SortControl.vue';
import SubjectCard from '@/components/suggestions/SubjectCard.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import Shelf from '@/pages/suggestions/Shelf.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { OpenedSubject, Suggestion, SuggestionOrigin, SuggestionSubject } from '@/types/suggestions';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

let visits: [string, Record<string, unknown>][] = [];

beforeEach(() => {
    useMediaTypes();
    visits = [];
    vi.spyOn(router, 'get').mockImplementation(((url: string, params: Record<string, unknown>) => visits.push([url, params])) as never);
    setPageProps({});
});

function page(given: {
    subject?: OpenedSubject | null;
    subjects?: SuggestionSubject[] | null;
    suggestions?: Suggestion[];
    origins?: SuggestionOrigin[];
    decades?: { decade: number; count: number }[];
}) {
    return mount(Shelf, {
        props: {
            shelf: { key: 'missing-from-collections', title: 'Missing from collections', description: 'Parts you have not seen.' },
            subject: given.subject ?? null,
            subjects: given.subjects ?? null,
            suggestions: given.suggestions ?? [],
            types: [{ type: 'movie', count: 2 }],
            origins: given.origins ?? [],
            decades: given.decades ?? [],
            sorts: given.subjects ? ['suggested', 'remaining', 'progress', 'release', 'title'] : ['suggested', 'release', 'rating', 'title'],
            filters: { type: null, origin: null, decade: null, q: null, sort: 'suggested', dir: 'asc' },
            pagination: { currentPage: 1, totalPages: 3, total: 2 },
        },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                PageToolbar: { template: '<div><slot name="search" /><slot name="filters" /><slot name="sort" /></div>' },
                MediaCard: true,
                Head: true,
            },
        },
    });
}

const saga: SuggestionSubject = {
    key: 'tmdb-10',
    title: 'The Saga',
    caption: '1 of 3 watched',
    finished: 1,
    total: 3,
    posterPath: '/saga.jpg',
    count: 2,
};
const opened: OpenedSubject = { key: 'tmdb-10', title: 'The Saga', caption: '1 of 3 watched', href: '/collection/tmdb/10' };

describe('a kind of suggestion', () => {
    it('lists its subjects as cards that open the subject, with no titles yet', () => {
        const wrapper = page({ subjects: [saga] });
        const card = wrapper.findComponent(SubjectCard);

        expect(card.find('a').attributes('href')).toBe('/suggestions.subject/missing-from-collections/tmdb-10');
        expect(card.text()).toContain('The Saga');
        expect(card.text()).toContain('1 of 3 watched');
        expect(card.text()).toContain('2 remaining');
        expect(wrapper.findComponent(MediaCard).exists()).toBe(false);
    });

    it('lists the titles of an opened subject under its name, with the way back and to its own page', () => {
        const wrapper = page({ subject: opened, suggestions: [{ media: mediaSummary({ ref: '2' }), reason: ['The Saga', '1 of 3 watched'] }] });

        expect(wrapper.find('h1').text()).toBe('The Saga');
        expect(wrapper.text()).toContain('1 of 3 watched');
        expect(wrapper.find('a[href="/suggestions.show/missing-from-collections"]').text()).toBe('Missing from collections');
        expect(wrapper.find('a[href="/collection/tmdb/10"]').exists()).toBe(true);
        // The heading already says why these are here.
        expect(wrapper.findAll('p.truncate')).toHaveLength(0);
    });

    it('puts the reason under the card, not in it, where there is no subject to head it', () => {
        const wrapper = page({ suggestions: [{ media: mediaSummary(), reason: ['Released May 1, 2026', 'Added Mar 14, 2026'] }] });

        expect(wrapper.find('h1').text()).toBe('Missing from collections');
        expect(wrapper.findComponent(MediaCard).props('subtitle')).toBeNull();
        expect(wrapper.findAll('p.truncate').map((line) => line.text())).toEqual(['Released May 1, 2026', 'Added Mar 14, 2026']);
    });

    it('says so when nothing is left', () => {
        expect(page({}).text()).toContain('Nothing on this shelf right now.');
    });

    it('offers the ways in only where the shelf has them, and keeps the pick in the address', async () => {
        expect(page({}).text()).not.toContain('Added by');

        const wrapper = page({ origins: [{ key: 'listed', label: 'On a list', count: 4 }] });
        const select = wrapper
            .findAll('label')
            .find((label) => label.text().includes('Added by'))
            ?.find('select');

        expect(select?.text()).toContain('On a list (4)');
        await select?.setValue('listed');

        expect(visits).toEqual([['/suggestions.show/missing-from-collections', { origin: 'listed' }]]);
    });

    it('offers a decade only when there is more than one, and names it by its years', async () => {
        expect(page({ decades: [{ decade: 1990, count: 3 }] }).text()).not.toContain('Released in');

        const wrapper = page({
            decades: [
                { decade: 2020, count: 5 },
                { decade: 1980, count: 2 },
            ],
        });
        const select = wrapper
            .findAll('label')
            .find((label) => label.text().includes('Released in'))
            ?.find('select');

        expect(select?.text()).toContain('1980–1989 (2)');
        // The option carries a number, so it is picked by its place, not by a string.
        (select?.element as HTMLSelectElement).selectedIndex = 2;
        await select?.trigger('change');

        expect(visits).toEqual([['/suggestions.show/missing-from-collections', { decade: 1980 }]]);
    });

    it("sorts by an order the page offers, naming a collection's release for what it is", () => {
        const wrapper = page({ subjects: [saga] });
        const sort = wrapper.findComponent(SortControl);

        expect(sort.props('options').map((option: { label: string }) => option.label)).toEqual([
            'Suggested order',
            'Left to watch',
            'Progress',
            'Newest missing title',
            'Title',
        ]);

        sort.vm.$emit('update:sort', 'remaining');
        sort.vm.$emit('update:sort', 'rating');
        sort.vm.$emit('update:dir', 'desc');

        expect(visits).toEqual([
            ['/suggestions.show/missing-from-collections', { sort: 'remaining' }],
            ['/suggestions.show/missing-from-collections', { dir: 'desc' }],
        ]);
    });

    it('keeps the type in the address, on the page it was picked on', () => {
        page({ subjects: [saga] })
            .findComponent(TypeFilter)
            .vm.$emit('update:modelValue', 'movie');
        page({ subject: opened }).findComponent(TypeFilter).vm.$emit('update:modelValue', 'movie');

        expect(visits).toEqual([
            ['/suggestions.show/missing-from-collections', { type: 'movie' }],
            ['/suggestions.subject/missing-from-collections/tmdb-10', { type: 'movie' }],
        ]);
    });
});
