import TitlePageLayout from '@/components/media/TitlePageLayout.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function layout(props: { fromLibrary?: boolean; removedAtSource?: boolean } = {}) {
    return mount(TitlePageLayout, {
        props,
        slots: {
            hero: '<div class="hero" />',
            header: '<h1>Dune</h1>',
            panels: '<div class="panel">Actions</div>',
            default: '<section class="overview">Overview</section>',
            below: '<section class="below" />',
            after: '<section class="after" />',
        },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });
}

describe('TitlePageLayout', () => {
    it('renders every part once — the panels too, on any screen', () => {
        const wrapper = layout();

        expect(wrapper.findAll('.panel')).toHaveLength(1);
        expect(wrapper.findAll('h1')).toHaveLength(1);
        expect(wrapper.find('.overview').exists()).toBe(true);
        expect(wrapper.find('.below').exists()).toBe(true);
        expect(wrapper.find('.after').exists()).toBe(true);
    });

    it('puts the panels between the title and the content in reading order', () => {
        const html = layout().html();

        expect(html.indexOf('Dune')).toBeLessThan(html.indexOf('Actions'));
        expect(html.indexOf('Actions')).toBeLessThan(html.indexOf('Overview'));
    });

    it('closes the page with recently viewed after everything else, and draws no frame without it', () => {
        const withRecent = mount(TitlePageLayout, {
            slots: { after: '<section class="after">After</section>', recent: '<section class="recent">Recent</section>' },
            global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
        });
        const html = withRecent.html();

        expect(html.indexOf('After')).toBeLessThan(html.indexOf('Recent'));
        expect(withRecent.find('.recent').element.parentElement?.classList.contains('max-w-content')).toBe(true);
        expect(layout().findAll('.max-w-content')).toHaveLength(1);
    });

    it('says the page comes from the library only when it does', () => {
        expect(layout().text()).not.toContain('The data provider is unavailable');
        expect(layout({ fromLibrary: true }).text()).toContain('The data provider is unavailable');
    });
});
