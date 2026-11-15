import CollectionSeriesLinks from '@/components/lists/CollectionSeriesLinks.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

describe('CollectionSeriesLinks', () => {
    it('names the series above, the subseries and the spin-off series, each linking to its collection', () => {
        const wrapper = mount(CollectionSeriesLinks, {
            props: {
                provider: 'igdb',
                relations: {
                    parent: { ref: '183', name: 'Tomb Raider', posterPath: null, backdropPath: null },
                    subseries: [
                        { ref: '9300', name: 'Legend', posterPath: null, backdropPath: null },
                        { ref: '9209', name: 'Survivor', posterPath: null, backdropPath: null },
                    ],
                    spinOffSeries: [],
                },
            },
            global: { stubs: { Link } },
        });

        expect(wrapper.findAll('p').map((row) => row.text().replace(/\s+/g, ' '))).toEqual([
            'Part of the series Tomb Raider',
            'Subseries Legend, Survivor',
        ]);
        expect(wrapper.findAll('a').map((link) => link.attributes('href'))).toEqual([
            '/collections.show/igdb/183',
            '/collections.show/igdb/9300',
            '/collections.show/igdb/9209',
        ]);
    });

    it('says nothing for a collection tied to nothing', () => {
        const wrapper = mount(CollectionSeriesLinks, {
            props: { provider: 'tmdb', relations: { parent: null, subseries: [], spinOffSeries: [] } },
            global: { stubs: { Link } },
        });

        expect(wrapper.find('div').exists()).toBe(false);
    });
});
