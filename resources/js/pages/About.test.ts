import About from '@/pages/About.vue';
import { setPageProps } from '@/test/page';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => setPageProps({}));

function about(sourceUrl = 'https://github.com/example/fork') {
    setPageProps({ version: '1.2.3', sourceUrl });

    return mount(About, {
        props: {
            dataSources: [
                {
                    name: 'Example Data',
                    url: 'https://data.example.com',
                    logo: null,
                    logoWidth: null,
                    logoHeight: null,
                    notices: ['Data under :license.'],
                    licenseName: 'CC0',
                    licenseUrl: 'https://creativecommons.org/publicdomain/zero/1.0/',
                },
            ],
        },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, Head: true, SectionHeading: true } },
    });
}

describe('the about page', () => {
    it('links the source code the instance runs, as the AGPL asks', () => {
        const link = about()
            .findAll('a')
            .find((anchor) => anchor.text() === 'Source code');

        expect(link?.attributes('href')).toBe('https://github.com/example/fork');
    });

    it('shows the version and links a credit to its licence', () => {
        const page = about();

        expect(page.text()).toContain('Version 1.2.3');
        expect(
            page
                .findAll('a')
                .find((anchor) => anchor.text() === 'CC0')
                ?.attributes('href'),
        ).toBe('https://creativecommons.org/publicdomain/zero/1.0/');
    });
});
