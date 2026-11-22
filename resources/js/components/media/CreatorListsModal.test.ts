import CreatorListsModal from '@/components/media/CreatorListsModal.vue';
import { useMediaModals } from '@/composables/useMediaModals';
import type { JsonFetchOptions } from '@/lib/http';
import { setPageProps } from '@/test/page';
import { router } from '@inertiajs/vue3';
import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';

type Call = { url: string; method: string; body: unknown };

const calls: Call[] = [];
// Membership reads waiting for the test to answer them, oldest first.
let membership: ((response: Response) => void)[] = [];

// The membership read waits for the test to answer it; writes land at once.
vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, { method = 'GET', body }: JsonFetchOptions = {}) => {
        calls.push({ url, method, body });

        if (url.includes('lists.show')) {
            return new Promise<Response>((resolve) => membership.push(resolve));
        }

        // A list write answers with the creator's lists, as the server does.
        const listIds = (body as { list_ids?: number[] } | undefined)?.list_ids;
        const answer = listIds ? { creatorStates: { [KEANU]: { followed: false, listIds } } } : { data: { id: 9 } };

        return Promise.resolve(new Response(JSON.stringify(answer), { status: 200 }));
    },
}));

const KEANU = 'person:tmdb:6384';

let wrapper: VueWrapper;

beforeEach(() => {
    vi.spyOn(router, 'reload').mockImplementation(((options: { onFinish: () => void }) => options.onFinish()) as never);
    calls.length = 0;
    membership = [];
    setPageProps({
        userLists: [
            { id: 4, title: 'Directors', systemKey: null, itemCount: 0 },
            { id: 7, title: 'Actors', systemKey: null, itemCount: 0 },
        ],
    });
    wrapper = mount(CreatorListsModal);
});

afterEach(() => {
    useMediaModals().close();
    wrapper.unmount();
});

const screen = () => new DOMWrapper(document.body);

async function open(ref = '6384'): Promise<void> {
    useMediaModals().openCreatorLists({ kind: 'person', provider: 'tmdb', ref }, 'Keanu Reeves');
    await flushPromises();
}

/** Answers the oldest membership read still waiting. */
async function answerMembership(status: number, listIds: number[] = [], key = KEANU): Promise<void> {
    membership.shift()?.(new Response(JSON.stringify({ data: { listIds }, creatorStates: { [key]: { followed: false, listIds } } }), { status }));
    await flushPromises();
}

const writes = () => calls.filter((call) => call.method !== 'GET');

describe('CreatorListsModal', () => {
    it('sends nothing until it knows which lists the person is on', async () => {
        await open();
        await screen().find('input').setValue('Favourites');
        await screen().find('form').trigger('submit');

        expect(screen().find('button[type="submit"]').attributes('disabled')).toBeDefined();
        expect(writes()).toEqual([]);

        await answerMembership(200, [4]);
        await screen().find('input').setValue('Favourites');
        await screen().find('form').trigger('submit');
        await flushPromises();

        expect(writes().at(-1)).toMatchObject({ method: 'PUT', body: { list_ids: [4, 9] } });
    });

    it('toggles one list and keeps the person on the others', async () => {
        await open();
        await answerMembership(200, [4]);

        const actors = screen()
            .findAll('button')
            .find((button) => button.text().startsWith('Actors'));
        await actors?.trigger('click');
        await flushPromises();

        expect(writes()).toEqual([expect.objectContaining({ method: 'PUT', body: { list_ids: [4, 7] } })]);
        // Both rows checked, from the answer.
        expect(
            screen()
                .findAll('button[aria-pressed="true"]')
                .map((button) => button.find('.truncate').text()),
        ).toEqual(['Directors', 'Actors']);
    });

    it('stays locked when the answer that lands is for the creator it showed before', async () => {
        await open('1');
        useMediaModals().close();
        await flushPromises();
        await open();
        await answerMembership(200, [], 'person:tmdb:1');

        await screen()
            .findAll('button')
            .find((button) => button.text().startsWith('Actors'))
            ?.trigger('click');
        await flushPromises();

        expect(writes()).toEqual([]);

        await answerMembership(200, [4]);
        await screen()
            .findAll('button')
            .find((button) => button.text().startsWith('Actors'))
            ?.trigger('click');
        await flushPromises();

        expect(writes()).toEqual([expect.objectContaining({ method: 'PUT', body: { list_ids: [4, 7] } })]);
    });

    it('says so when the lists could not be read, and stays read-only', async () => {
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);

        await open();
        await answerMembership(500);
        await screen().find('input').setValue('Favourites');
        await screen().find('form').trigger('submit');

        expect(error).toHaveBeenCalledWith('Something went wrong. Please try again.');
        expect(writes()).toEqual([]);
    });
});
