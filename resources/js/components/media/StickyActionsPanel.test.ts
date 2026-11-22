import StickyActionsPanel from '@/components/media/StickyActionsPanel.vue';
import { useMediaModals } from '@/composables/useMediaModals';
import type { JsonFetchOptions } from '@/lib/http';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { UserMediaState } from '@/types/generated';

import { nth } from '@/test/lists';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const sent: unknown[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (_: string, { body }: JsonFetchOptions = {}) => {
        sent.push(body);

        return Promise.resolve(new Response(JSON.stringify({ data: {}, states: {} }), { status: 200 }));
    },
}));

const show = mediaSummary({ type: 'show', ref: 's1' });

beforeEach(() => useMediaTypes());

afterEach(async () => {
    await flushPromises();
    sent.length = 0;
    useMediaModals().close();
});

/** The bell row as the panel shows it for a title in this state. */
function bellFor(state: Partial<UserMediaState>) {
    setPageProps({ userLists: [], userStates: { 'show:s1': userState(state) } });

    const buttons = mount(StickyActionsPanel, { props: { media: show } }).findAll('button');

    return nth(buttons, buttons.length - 1);
}

describe('the bell', () => {
    it.each([
        ['nothing tracks the title', {}, 'Follow releases', 'follow'],
        ['the user rang it', { follow: 'follow', followSource: 'followed' }, 'Following', null],
        ['a creator rang it', { follow: 'follow', followVia: 'Keanu Reeves', followSource: 'creator' }, 'Following (Keanu Reeves)', 'mute'],
        ['the backlog outranks a rung bell', { follow: 'follow', followSource: 'backlog' }, 'Following (backlog)', 'mute'],
        ['history tracks it with no bell', { followSource: 'activity' }, 'Following (watching)', 'mute'],
        ['a drop outranks the bell', { follow: 'follow', followSource: 'dropped' }, 'Following (dropped)', 'mute'],
        ['it is muted', { follow: 'mute', followSource: null }, 'Muted', null],
    ] as const)('when %s it says so, and a click moves it on', async (_, state, label, next) => {
        const bell = bellFor(state);

        expect(bell.text()).toBe(label);

        await bell.trigger('click');
        await flushPromises();

        expect(sent).toEqual([{ mode: next }]);
    });

    it('is not offered on a season', () => {
        setPageProps({ userLists: [], userStates: {} });
        const labels = mount(StickyActionsPanel, { props: { media: mediaSummary({ type: 'season', ref: 's1:1' }) } })
            .findAll('button')
            .map((button) => button.text());

        expect(labels).not.toContain('Follow releases');
    });
});

describe('the other rows', () => {
    function panel() {
        setPageProps({ userLists: [{ id: 5, systemKey: 'watchlist', itemCount: 0 }], userStates: { 'show:s1': userState() } });

        return mount(StickyActionsPanel, { props: { media: show } }).findAll('button');
    }

    it('open the rating and the lists, and save a favorite', async () => {
        const buttons = panel();
        const rate = nth(buttons, 0);
        const lists = nth(buttons, 1);
        const favorite = nth(buttons, 2);

        await rate.trigger('click');
        await lists.trigger('click');
        await favorite.trigger('click');
        await flushPromises();

        expect(useMediaModals().modals.rate).toEqual(show);
        expect(useMediaModals().modals.lists).toEqual(show);
        expect(sent).toEqual([{ favorite: true }]);
    });

    it('put the title on the backlog with a right click on the lists row, and nowhere else', async () => {
        const buttons = panel();
        const rate = nth(buttons, 0);
        const lists = nth(buttons, 1);

        await rate.trigger('contextmenu');
        await lists.trigger('contextmenu');
        await flushPromises();

        expect(sent).toEqual([{ list_ids: [5] }]);
    });
});
