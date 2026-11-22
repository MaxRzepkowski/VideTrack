import Search from '@/pages/media/Search.vue';
import { useMediaTypes } from '@/test/factories';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const SCOPES = [
    { key: 'tmdb', types: ['show', 'movie'] },
    { key: 'igdb', types: ['game'] },
    { key: 'person', types: ['person'] },
];

function page(query = '') {
    return mount(Search, {
        props: { query, scope: 'tmdb', scopes: SCOPES, results: null, unavailable: false },
        attachTo: document.body,
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });
}

beforeEach(() => {
    useMediaTypes();
    vi.spyOn(router, 'get').mockImplementation(() => undefined);
});

describe('Search', () => {
    it('takes the cursor as it opens', () => {
        const wrapper = page();

        expect(document.activeElement).toBe(wrapper.find('input').element);

        wrapper.unmount();
    });

    it('offers each scope as a button named by its kinds, a scope of two kinds wearing two glyphs', () => {
        const wrapper = page();
        const buttons = wrapper.findAll('[role=group] button');

        expect(buttons.map((button) => button.text())).toEqual(['Shows and movies', 'Games', 'People']);
        expect(buttons[0]!.attributes('aria-pressed')).toBe('true');
        expect(buttons[1]!.attributes('aria-pressed')).toBe('false');
        expect(buttons[0]!.findAll('svg')).toHaveLength(2);

        wrapper.unmount();
    });

    it('switches the scope on a click, searching again only when there is something to search', async () => {
        const wrapper = page();

        await wrapper.findAll('[role=group] button')[1]!.trigger('click');

        expect(wrapper.findAll('[role=group] button')[1]!.attributes('aria-pressed')).toBe('true');
        expect(router.get).not.toHaveBeenCalled();

        await wrapper.find('input').setValue('hollow');
        await wrapper.findAll('[role=group] button')[0]!.trigger('click');

        expect(router.get).toHaveBeenCalledWith('/search', { q: 'hollow', scope: 'tmdb' }, expect.anything());

        wrapper.unmount();
    });

    it('clears the box with its button and with Esc, and offers to clear only when there is text', async () => {
        const wrapper = page('hollow');
        const input = wrapper.find('input');
        const clear = () => wrapper.find('button[aria-label="Clear search"]');

        await clear().trigger('click');

        expect((input.element as HTMLInputElement).value).toBe('');
        expect(clear().exists()).toBe(false);

        await input.setValue('knight');
        await input.trigger('keydown', { key: 'Escape' });

        expect((input.element as HTMLInputElement).value).toBe('');

        wrapper.unmount();
    });
});
