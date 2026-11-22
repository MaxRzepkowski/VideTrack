import PersonWork from '@/components/creators/PersonWork.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import { nth } from '@/test/lists';
import { setPageProps } from '@/test/page';
import type { PersonCredit, PersonDetails } from '@/types/media';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const MediaCard = { props: ['media', 'subtitle'], template: '<div class="card">{{ media.title }}</div>' };

function credit(ref: string, department: PersonCredit['department'], type = 'movie'): PersonCredit {
    return { media: mediaSummary({ type, ref, title: `T${ref}` }), department, role: null, popularity: Number(ref) };
}

function person(credits: PersonCredit[]): PersonDetails {
    return {
        ref: '6384',
        name: 'Keanu Reeves',
        profilePath: null,
        biography: null,
        knownForDepartment: 'acting',
        birthday: null,
        deathday: null,
        placeOfBirth: null,
        origin: null,
        alsoKnownAs: [],
        photos: [],
        externalLinks: {},
        credits,
    };
}

function work(credits: PersonCredit[]) {
    return mount(PersonWork, { props: { person: person(credits) }, global: { stubs: { MediaCard }, directives: { tooltip: vTooltip } } });
}

beforeEach(() => {
    useMediaTypes();
    setPageProps({ userLists: [{ id: 5, systemKey: 'watchlist', itemCount: 0 }] });
});

describe('PersonWork', () => {
    it('opens on what the person is known for and on their films, most popular first', () => {
        const wrapper = work([credit('1', 'acting'), credit('3', 'acting'), credit('2', 'directing'), credit('4', 'acting', 'show')]);

        expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('acting');
        expect(wrapper.findAll('.card').map((card) => card.text())).toEqual(['T3', 'T1']);
    });

    it('puts the titles on the backlog and in the history above the credits', () => {
        setPageProps({
            userStates: {
                'movie:1': userState({ listIds: [5] }),
                'movie:2': userState({ watched: true, plays: 1 }),
            },
        });

        const wrapper = work([credit('1', 'acting'), credit('2', 'acting'), credit('3', 'acting')]);
        const shelves = wrapper.findAll('section').map((section) => section.findAll('.card').map((card) => card.text()));

        expect(shelves).toEqual([['T1'], ['T2'], ['T3', 'T2', 'T1']]);
    });

    it('sorts the credits by title, date or rating, and switches department', async () => {
        const titled = (ref: string, title: string, releaseDate: string, communityRating: number): PersonCredit => ({
            media: mediaSummary({ ref, title, releaseDate, communityRating }),
            department: 'acting',
            role: 'Neo',
            popularity: 1,
        });
        const wrapper = work([titled('1', 'Beta', '2001-01-01', 5), titled('2', 'Alpha', '1999-01-01', 9), credit('3', 'directing')]);
        const cards = () => wrapper.findAll('.card').map((card) => card.text());
        const sort = () => wrapper.findAll('select').at(-1)!;

        await sort().setValue('title');
        expect(cards()).toEqual(['Alpha', 'Beta']);

        await sort().setValue('release');
        expect(cards()).toEqual(['Beta', 'Alpha']);

        await sort().setValue('rating');
        expect(cards()).toEqual(['Alpha', 'Beta']);

        await nth(wrapper.findAll('select'), 0).setValue('directing');
        expect(cards()).toEqual(['T3']);
    });

    it('folds a long shelf and lets it open', async () => {
        const wrapper = work(Array.from({ length: 30 }, (_, n) => credit(String(n + 1), 'acting')));

        expect(wrapper.findAll('.card').length).toBeLessThan(30);

        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Show'))!
            .trigger('click');
        expect(wrapper.findAll('.card')).toHaveLength(30);
    });

    describe('the type select', () => {
        const typeSelect = (wrapper: ReturnType<typeof work>) =>
            wrapper.findAll('select').find((select) => select.attributes('aria-label') === 'Filter by type');
        const options = (wrapper: ReturnType<typeof work>) =>
            typeSelect(wrapper)
                ?.findAll('option')
                .map((option) => option.text());
        const cards = (wrapper: ReturnType<typeof work>) => wrapper.findAll('.card').map((card) => card.text());

        it('offers films, then shows, each counted once per title in the department, and no "All"', () => {
            const wrapper = work([
                credit('1', 'acting'),
                credit('1', 'acting'),
                credit('2', 'acting', 'show'),
                credit('3', 'acting', 'show'),
                credit('4', 'directing'),
            ]);

            expect(options(wrapper)).toEqual(['Movies (1)', 'Shows (2)']);
        });

        it('switches to the shows by hand', async () => {
            const wrapper = work([credit('1', 'acting'), credit('2', 'acting', 'show'), credit('3', 'acting', 'show')]);

            await typeSelect(wrapper)?.setValue('show');

            expect(cards(wrapper)).toEqual(['T3', 'T2']);
        });

        it('is not there when the department holds one type only', () => {
            expect(typeSelect(work([credit('1', 'author', 'book'), credit('2', 'author', 'book')]))).toBeUndefined();
        });

        it('falls back to the first type in a department without the chosen one', async () => {
            const wrapper = work([
                credit('1', 'acting'),
                credit('2', 'acting', 'show'),
                credit('3', 'directing'),
                credit('4', 'directing', 'show'),
                credit('5', 'writing'),
            ]);

            await typeSelect(wrapper)?.setValue('show');
            await nth(wrapper.findAll('select'), 0).setValue('directing');
            expect(cards(wrapper)).toEqual(['T4']);

            await nth(wrapper.findAll('select'), 0).setValue('writing');
            expect(cards(wrapper)).toEqual(['T5']);
        });

        it('folds an opened shelf again when another type is picked', async () => {
            const wrapper = work([
                ...Array.from({ length: 30 }, (_, n) => credit(String(n + 1), 'acting')),
                ...Array.from({ length: 30 }, (_, n) => credit(String(n + 100), 'acting', 'show')),
            ]);

            await wrapper
                .findAll('button')
                .find((button) => button.text().includes('Show'))!
                .trigger('click');
            expect(cards(wrapper)).toHaveLength(30);

            await typeSelect(wrapper)?.setValue('show');

            expect(cards(wrapper).length).toBeLessThan(30);
        });
    });
});
