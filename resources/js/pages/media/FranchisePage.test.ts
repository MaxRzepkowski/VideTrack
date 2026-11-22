import SortControl from '@/components/SortControl.vue';
import FranchisePage from '@/pages/media/FranchisePage.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const MediaCard = { props: ['media'], template: '<div class="card">{{ media.title }}</div>' };

const game = (title: string, releaseDate: string | null, communityRating: number | null = null) =>
    mediaSummary({ type: 'game', ref: title, provider: 'igdb', title, releaseDate, communityRating });

function page(series = [game('Resident Evil', '1996-03-22', 8.8), game('Resident Evil 2', '1998-01-21', 9.3), game('Resident Evil Requiem', null)]) {
    return mount(FranchisePage, {
        props: {
            provider: 'igdb',
            franchise: { ref: '7', name: 'Resident Evil', series, others: [game('Resident Evil Survivor', '2000-01-27', 4.1)] },
        },
        global: {
            stubs: { AppLayout: { template: '<div><slot /></div>' }, Head: true, MediaCard },
        },
    });
}

beforeEach(() => useMediaTypes());

describe('FranchisePage', () => {
    it("shows the series' games first, in release order with the announced last, the rest one choice away", async () => {
        const wrapper = page();
        const cards = () => wrapper.findAll('.card').map((card) => card.text());

        expect(wrapper.find('h1').text()).toBe('Resident Evil');
        expect(cards()).toEqual(['Resident Evil', 'Resident Evil 2', 'Resident Evil Requiem']);
        expect(
            wrapper
                .findAll('select')
                .at(0)!
                .findAll('option')
                .map((option) => option.text()),
        ).toEqual(['In a series (3)', 'Other games (1)']);

        await wrapper.findAll('select').at(0)!.setValue('others');
        expect(cards()).toEqual(['Resident Evil Survivor']);
    });

    it('turns the release order round with the announced still last, and sorts by rating best first', async () => {
        const wrapper = page();
        const sort = wrapper.findComponent(SortControl);
        const cards = () => wrapper.findAll('.card').map((card) => card.text());

        await sort.vm.$emit('update:dir', 'desc');
        expect(cards()).toEqual(['Resident Evil 2', 'Resident Evil', 'Resident Evil Requiem']);

        await sort.vm.$emit('update:sort', 'rating');
        expect(cards()).toEqual(['Resident Evil 2', 'Resident Evil', 'Resident Evil Requiem']);
    });

    it('needs no choice when the franchise is one series', () => {
        const wrapper = mount(FranchisePage, {
            props: { provider: 'igdb', franchise: { ref: '7', name: 'X', series: [game('A', '2000-01-01')], others: [] } },
            global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, Head: true, MediaCard } },
        });

        // The sort control's own select only.
        expect(wrapper.findAll('select')).toHaveLength(1);
    });
});
