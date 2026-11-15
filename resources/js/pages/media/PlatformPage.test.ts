import PlatformPage from '@/pages/media/PlatformPage.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const MediaCard = { props: ['media'], template: '<div class="card">{{ media.title }}</div>' };

const game = (title: string) => mediaSummary({ type: 'game', ref: title, provider: 'igdb', title });

function page(upcoming = [game('Coming')], topRated = [game('Best')]) {
    return mount(PlatformPage, {
        props: {
            provider: 'igdb',
            platform: {
                ref: '167',
                name: 'PlayStation 5',
                abbreviation: 'PS5',
                logoPath: null,
                kind: 'Console',
                family: 'PlayStation',
                generation: 9,
                summary: null,
                upcoming,
                topRated,
            },
        },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, Head: true, MediaCard } },
    });
}

beforeEach(() => useMediaTypes());

describe('PlatformPage', () => {
    it('says what machine it is and opens on the games coming, the best rated one choice away', async () => {
        const wrapper = page();
        const cards = () => wrapper.findAll('.card').map((card) => card.text());

        expect(wrapper.find('h1').text()).toBe('PlayStation 5');
        expect(wrapper.text()).toContain('Console');
        expect(wrapper.text()).toContain('9');
        expect(cards()).toEqual(['Coming']);

        await wrapper.find('select').setValue('topRated');
        expect(cards()).toEqual(['Best']);
    });

    it('needs no choice with one shelf', () => {
        expect(
            page([], [game('Best')])
                .find('select')
                .exists(),
        ).toBe(false);
    });
});
