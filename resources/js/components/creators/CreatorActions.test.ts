import CreatorActions from '@/components/creators/CreatorActions.vue';
import { useMediaModals } from '@/composables/useMediaModals';
import type { JsonFetchOptions } from '@/lib/http';
import { nth } from '@/test/lists';
import { setPageProps } from '@/test/page';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

const calls: { url: string; body: unknown }[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, { body }: JsonFetchOptions = {}) => {
        calls.push({ url, body });

        return Promise.resolve(
            new Response(JSON.stringify({ creatorStates: { 'network:tmdb:49': { followed: true, listIds: [] } } }), { status: 200 }),
        );
    },
}));

const network = { kind: 'network', provider: 'tmdb', ref: '49' } as const;

afterEach(() => {
    calls.length = 0;
    useMediaModals().close();
});

const buttons = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('button');

describe('CreatorActions', () => {
    it('names the lists the creator is on, and says when the bell is on', () => {
        setPageProps({ creatorStates: { 'network:tmdb:49': { followed: true, listIds: [3, 4] } } });

        const wrapper = mount(CreatorActions, { props: { creator: network, name: 'HBO' } });

        expect(buttons(wrapper).map((button) => button.text())).toEqual(['On 2 lists', 'Following']);
        // The lists button opens a dialog; the bell is a switch that is on.
        expect(buttons(wrapper).map((button) => button.attributes('aria-haspopup') ?? button.attributes('aria-pressed'))).toEqual(['dialog', 'true']);
    });

    it('offers both actions for a creator nobody touched', () => {
        const wrapper = mount(CreatorActions, { props: { creator: network, name: 'HBO' } });

        expect(buttons(wrapper).map((button) => button.text())).toEqual(['Add to list', 'Follow releases']);
    });

    it('opens the lists of this creator, and rings the bell from the answer', async () => {
        const wrapper = mount(CreatorActions, { props: { creator: network, name: 'HBO' } });

        await nth(buttons(wrapper), 0).trigger('click');
        expect(useMediaModals().modals.creatorLists).toEqual({ creator: network, name: 'HBO' });

        await nth(buttons(wrapper), 1).trigger('click');
        await flushPromises();

        expect(calls[0]).toEqual({ url: '/ui.networks.follow.update/tmdb/49', body: { mode: 'follow' } });
        expect(nth(buttons(wrapper), 1).text()).toBe('Following');
    });
});
