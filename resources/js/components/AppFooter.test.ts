import AppFooter from '@/components/AppFooter.vue';
import { setPageProps } from '@/test/page';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

function footer(version: string) {
    setPageProps({ version, sourceUrl: 'https://github.com/example/fork' });

    return mount(AppFooter, { global: { stubs: { Link } } });
}

describe('AppFooter', () => {
    it('names the running version and links the code it runs', () => {
        const page = footer('1.4.0');

        expect(page.text()).toContain('v1.4.0');
        expect(
            page
                .findAll('a')
                .find((anchor) => anchor.text() === 'Source code')
                ?.attributes('href'),
        ).toBe('https://github.com/example/fork');
    });

    it('shows a build label as it is', () => {
        const text = footer('dev').text();

        expect(text).toContain('dev');
        expect(text).not.toContain('vdev');
    });
});
