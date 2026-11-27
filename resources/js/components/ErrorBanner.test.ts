import ErrorBanner from '@/components/ErrorBanner.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('ErrorBanner', () => {
    it('says what went wrong as an alert', () => {
        const wrapper = mount(ErrorBanner, { props: { message: 'The source is unavailable.' } });

        expect(wrapper.find('[role="alert"]').text()).toBe('The source is unavailable.');
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('leads to where the rest is said, as a link', () => {
        const wrapper = mount(ErrorBanner, {
            props: { message: '1 issue needs your attention.', link: { href: '/settings/instance', label: 'See' } },
            global: { stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } },
        });

        expect(wrapper.find('a').attributes('href')).toBe('/settings/instance');
        expect(wrapper.find('a').text()).toBe('See');
    });
});
