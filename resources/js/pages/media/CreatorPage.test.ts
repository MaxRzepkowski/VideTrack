import ClampedText from '@/components/ClampedText.vue';
import CompanyCatalogue from '@/components/creators/CompanyCatalogue.vue';
import CreatorActions from '@/components/creators/CreatorActions.vue';
import PersonWork from '@/components/creators/PersonWork.vue';
import DetailsModal from '@/components/media/DetailsModal.vue';
import CreatorPage from '@/pages/media/CreatorPage.vue';
import { useMediaTypes } from '@/test/factories';
import { nth } from '@/test/lists';
import type { CompanyDetails, PersonDetails } from '@/types/media';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const keanu: PersonDetails = {
    ref: '6384',
    name: 'Keanu Reeves',
    profilePath: null,
    biography: null,
    knownForDepartment: 'acting',
    birthday: '1964-09-02',
    deathday: null,
    placeOfBirth: 'Beirut, Lebanon',
    origin: null,
    alsoKnownAs: ['K. Reeves'],
    photos: [],
    externalLinks: {},
    credits: [],
};

const hbo: CompanyDetails = {
    ref: '49',
    name: 'HBO',
    logoPath: null,
    description: null,
    founded: null,
    country: 'US',
    parentName: null,
    parentRef: null,
    externalLinks: {},
    credits: [],
    hasMoreCredits: false,
};

function render(props: { kind: 'person'; creator: PersonDetails } | { kind: 'company' | 'network'; creator: CompanyDetails }) {
    return shallowMount(CreatorPage, {
        props: { provider: 'tmdb', ...props },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });
}

beforeEach(() => {
    useMediaTypes();
    vi.useFakeTimers({ now: new Date('2026-10-10T12:00:00Z'), toFake: ['Date'] });
});

describe('CreatorPage', () => {
    it("shows a person's facts with their age, and their work below", () => {
        const wrapper = render({ kind: 'person', creator: keanu });

        expect(wrapper.text()).toContain('Keanu Reeves');
        expect(wrapper.text()).toContain('(62)');
        expect(wrapper.findComponent(PersonWork).exists()).toBe(true);
        expect(wrapper.findComponent(CompanyCatalogue).exists()).toBe(false);
        expect(wrapper.findComponent(DetailsModal).props('rows')).toEqual([
            { label: 'Also known as', value: 'K. Reeves' },
            { label: 'Place of birth', value: 'Beirut, Lebanon' },
            { label: 'From', value: null },
        ]);
    });

    it("shows a network's country and its catalogue, its actions placed for each width of the layout", () => {
        const wrapper = render({ kind: 'network', creator: hbo });

        expect(wrapper.findComponent(CompanyCatalogue).props()).toMatchObject({ provider: 'tmdb', kind: 'network', company: hbo });
        expect(wrapper.findComponent(PersonWork).exists()).toBe(false);
        expect(wrapper.findComponent(DetailsModal).props('refresh')).toEqual({ url: '/network.refresh/tmdb/49' });

        const actions = wrapper.findAllComponents(CreatorActions);

        // Under the portrait on a phone; atop the right column in two columns, and in the third column on a wide screen.
        expect(actions.map((action) => action.classes().filter((name) => name.includes('hidden')))).toEqual([
            ['md:hidden'],
            ['hidden', 'lg:hidden'],
            [],
        ]);
        expect(nth(actions, 0).props()).toEqual({ creator: { kind: 'network', provider: 'tmdb', ref: '49' }, name: 'HBO' });
    });

    it("shows a studio's founding, country and parent, linking the parent, and its description", () => {
        const wrapper = render({
            kind: 'company',
            creator: {
                ...hbo,
                ref: '420',
                name: 'Marvel Studios',
                founded: '1993-12-07',
                parentName: 'Disney',
                parentRef: '2',
                description: 'A studio.',
            },
        });

        expect(wrapper.text()).toContain('Founded');
        expect(wrapper.text()).toContain('Parent company');
        expect(wrapper.findComponent(ClampedText).props('text')).toBe('A studio.');
        expect(wrapper.findComponent(DetailsModal).props('rows')).toEqual([
            { label: 'Founded', value: expect.any(String) },
            { label: 'Country', value: 'United States' },
            { label: 'Parent company', value: 'Disney' },
        ]);
    });

    it("tells a dead person's age at death and keeps the biography clamped", () => {
        const wrapper = render({ kind: 'person', creator: { ...keanu, deathday: '2000-09-02', biography: 'A life.' } });

        expect(wrapper.text()).toContain('(36)');
        expect(wrapper.findComponent(ClampedText).props('text')).toBe('A life.');
    });
});
