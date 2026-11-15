import CompanyCatalogue from '@/components/creators/CompanyCatalogue.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { nth } from '@/test/lists';
import type { CompanyCredit, CompanyDetails } from '@/types/media';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const MediaCard = { props: ['media'], template: '<div class="card">{{ media.title }}</div>' };

function credit(title: string, role: CompanyCredit['role'], category: CompanyCredit['category'], communityRating = 7): CompanyCredit {
    return { media: mediaSummary({ ref: title, title, communityRating, releaseDate: '2020-01-01' }), role, category };
}

function company(credits: CompanyCredit[]): CompanyDetails {
    return {
        ref: '1',
        name: 'Maker',
        logoPath: null,
        description: null,
        founded: null,
        country: null,
        parentName: null,
        parentRef: null,
        externalLinks: {},
        credits,
        hasMoreCredits: false,
    };
}

function catalogue(kind: 'company' | 'network', credits: CompanyCredit[]) {
    return mount(CompanyCatalogue, {
        props: { provider: 'tmdb', kind, company: company(credits) },
        global: { stubs: { MediaCard }, directives: { tooltip: vTooltip } },
    });
}

beforeEach(() => useMediaTypes());

describe('CompanyCatalogue', () => {
    it("shows a game company's titles one role and one type at a time, developed games first, best rated first", async () => {
        const wrapper = catalogue('company', [
            credit('Good', 'developer', 'game', 6),
            credit('Best', 'developer', 'game', 9),
            credit('Sold', 'publisher', 'game'),
            credit('Add-on', 'developer', 'dlc'),
            credit('Ported', 'porting', 'game'),
            credit('Helped', 'supporting', 'game'),
        ]);
        const options = (index: number) =>
            nth(wrapper.findAll('select'), index)
                .findAll('option')
                .map((option) => option.text());
        const cards = () => wrapper.findAll('.card').map((card) => card.text());

        expect(wrapper.find('h2').text()).toBe('Games');
        expect(options(0)).toEqual(['Developed', 'Published', 'Ported', 'Supported']);
        expect(options(1)).toEqual(['Games', 'DLCs']);
        expect(wrapper.findAll('h3')).toHaveLength(0);
        expect(cards()).toEqual(['Best', 'Good']);

        // The publisher shelf has games only: no type to pick between.
        await nth(wrapper.findAll('select'), 0).setValue('publisher');
        expect(cards()).toEqual(['Sold']);
        expect(wrapper.findAll('select')).toHaveLength(2);

        // The type picked under one role holds when the other has it too.
        await nth(wrapper.findAll('select'), 0).setValue('developer');
        await nth(wrapper.findAll('select'), 1).setValue('dlc');
        expect(cards()).toEqual(['Add-on']);
        await nth(wrapper.findAll('select'), 0).setValue('publisher');
        expect(cards()).toEqual(['Sold']);
    });

    it("gives a network's single shelf of shows no heading of its own and no type picker", () => {
        const wrapper = catalogue('network', [credit('Show A', 'network', 'show'), credit('Show B', 'network', 'show', 9)]);

        expect(wrapper.find('h2').text()).toBe('Shows');
        // The sort control alone: one role and one type have nothing to pick between.
        expect(wrapper.findAll('select')).toHaveLength(1);
        expect(wrapper.findAll('.card').map((card) => card.text())).toEqual(['Show B', 'Show A']);
    });

    it('puts what is still to come first, soonest first, and sorts by title or date when asked', async () => {
        const dated = (title: string, releaseDate: string, communityRating: number | null = 7): CompanyCredit => ({
            media: mediaSummary({ ref: title, title, releaseDate, communityRating }),
            role: 'studio',
            category: 'movie',
        });
        const wrapper = catalogue('company', [dated('Old', '1999-01-01', 9), dated('Later', '2030-01-01', null), dated('Soon', '2027-01-01', null)]);
        const cards = () => wrapper.findAll('.card').map((card) => card.text());

        expect(cards()).toEqual(['Soon', 'Later', 'Old']);

        await wrapper.findAll('select').at(-1)!.setValue('title');
        expect(cards()).toEqual(['Later', 'Old', 'Soon']);

        await wrapper.findAll('select').at(-1)!.setValue('release');
        expect(cards()).toEqual(['Later', 'Soon', 'Old']);
    });

    it('switches the type shelved and folds a long shelf behind its toggle', async () => {
        const many = Array.from({ length: 20 }, (_, n) => credit(`G${n}`, 'developer', 'game', n));
        const wrapper = catalogue('company', [...many, credit('Add-on', 'developer', 'dlc')]);

        expect(wrapper.findAll('.card').length).toBeLessThan(20);

        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Show'))!
            .trigger('click');
        expect(wrapper.findAll('.card')).toHaveLength(20);

        await nth(wrapper.findAll('select'), 0).setValue('dlc');
        expect(wrapper.findAll('.card').map((card) => card.text())).toEqual(['Add-on']);
        // Another type folds the shelf again.
        await nth(wrapper.findAll('select'), 0).setValue('game');
        expect(wrapper.findAll('.card').length).toBeLessThan(20);
    });
});
