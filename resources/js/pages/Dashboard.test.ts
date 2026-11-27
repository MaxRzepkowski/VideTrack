import ErrorBanner from '@/components/ErrorBanner.vue';
import Dashboard from '@/pages/Dashboard.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function page(attention: number) {
    return shallowMount(Dashboard, {
        props: { stats: {}, upNext: [], playing: [], calendar: [], attention },
        global: { renderStubDefaultSlot: true },
    });
}

describe('Dashboard', () => {
    it('tells the admin how much of the instance needs them, and where to see it', () => {
        const banner = page(2).findComponent(ErrorBanner);

        expect(banner.props('message')).toBe('2 issues need your attention.');
        expect(banner.props('link')).toEqual({ href: '/settings.instance.index', label: 'See' });
    });

    it('says nothing when nothing needs anyone', () => {
        expect(page(0).findComponent(ErrorBanner).exists()).toBe(false);
    });
});
