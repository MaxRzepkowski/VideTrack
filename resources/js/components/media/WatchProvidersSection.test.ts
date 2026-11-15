import WatchProvidersSection from '@/components/media/WatchProvidersSection.vue';
import { vTooltip } from '@/directives/tooltip';
import type { WatchAvailability } from '@/types/media';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function availability(offers: Partial<WatchAvailability>): WatchAvailability {
    return { region: 'US', link: null, stream: [], free: [], rent: [], buy: [], ...offers };
}

function section(providers: WatchAvailability | null) {
    return mount(WatchProvidersSection, { props: { providers }, global: { directives: { tooltip: vTooltip } } });
}

describe('WatchProvidersSection', () => {
    it('offers only the kinds a title is available in, free ones among them', () => {
        const wrapper = section(availability({ free: [{ name: 'Tubi', logoPath: null }], rent: [{ name: 'Apple TV', logoPath: null }] }));

        expect(wrapper.findAll('option').map((option) => option.text())).toEqual(['Free', 'Rent']);
        expect(wrapper.text()).toContain('Tubi');
    });

    it('stays away when there is nothing to watch on', () => {
        expect(section(availability({})).find('section').exists()).toBe(false);
        expect(section(null).find('section').exists()).toBe(false);
    });
});
