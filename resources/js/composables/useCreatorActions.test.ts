import { useCreatorActions, type Creator } from '@/composables/useCreatorActions';
import { useCreatorState } from '@/composables/useCreatorState';
import type { JsonFetchOptions } from '@/lib/http';
import { nth } from '@/test/lists';
import { reloadProps, setPageProps } from '@/test/page';
import { router } from '@inertiajs/vue3';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';

type Answer = { status: number; body: unknown };

const calls: { url: string; options: JsonFetchOptions }[] = [];
let answers: Answer[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, options: JsonFetchOptions = {}) => {
        calls.push({ url, options });
        const { status, body } = answers.shift() ?? { status: 200, body: {} };

        return Promise.resolve(new Response(JSON.stringify(body), { status }));
    },
}));

const company: Creator = { kind: 'company', provider: 'tmdb', ref: '3' };

beforeEach(() => {
    calls.length = 0;
    answers = [];
    vi.spyOn(router, 'reload').mockImplementation(((options: { onFinish: () => void }) => options.onFinish()) as never);
});

describe('useCreatorActions', () => {
    it("takes in the lists a creator is on, and says so when the answer doesn't come", async () => {
        const { loadLists } = useCreatorActions();

        answers = [
            { status: 200, body: { data: { listIds: [4, 7] }, creatorStates: { 'company:tmdb:3': { followed: false, listIds: [4, 7] } } } },
            { status: 500, body: {} },
        ];

        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);

        expect(await loadLists(company)).toBe(true);
        expect(useCreatorState(company).value.listIds).toEqual([4, 7]);
        expect(await loadLists(company)).toBe(false);
        expect(nth(calls, 0).url).toBe('/ui.companies.lists.show/tmdb/3');
        expect(error).toHaveBeenCalledWith('Something went wrong. Please try again.');
    });

    it('puts the creator on one list, keeping its other lists, and takes the new state from the answer', async () => {
        const keanu: Creator = { kind: 'person', provider: 'tmdb', ref: '6384' };
        setPageProps({ creatorStates: { 'person:tmdb:6384': { followed: false, listIds: [2] } } });
        answers = [{ status: 200, body: { creatorStates: { 'person:tmdb:6384': { followed: false, listIds: [2, 4] } } } }];

        expect(await useCreatorActions().toggleList(keanu, 4)).toBe(true);

        expect(calls[0]).toMatchObject({ url: '/ui.people.lists.update/tmdb/6384', options: { method: 'PUT', body: { list_ids: [2, 4] } } });
        expect(useCreatorState(keanu).value.listIds).toEqual([2, 4]);
        expect(router.reload).not.toHaveBeenCalled();
    });

    it("sends a creator's second click after the first has landed, built from the state it left", async () => {
        const { toggleList, toggleFollow } = useCreatorActions();
        answers = [
            { status: 200, body: { creatorStates: { 'company:tmdb:3': { followed: false, listIds: [4] } } } },
            { status: 200, body: { creatorStates: { 'company:tmdb:3': { followed: false, listIds: [4, 7] } } } },
            { status: 200, body: { creatorStates: { 'company:tmdb:3': { followed: true, listIds: [4, 7] } } } },
        ];

        await Promise.all([toggleList(company, 4), toggleList(company, 7), toggleFollow(company)]);

        expect(calls.map((call) => call.options.body)).toEqual([{ list_ids: [4] }, { list_ids: [4, 7] }, { mode: 'follow' }]);
        expect(useCreatorState(company).value).toEqual({ followed: true, listIds: [4, 7] });
    });

    it('creates a list with the creator already on it, and reloads the lists too', async () => {
        setPageProps({ creatorStates: { 'company:tmdb:3': { followed: false, listIds: [4] } } });
        answers = [{ status: 201, body: { data: { id: 9 } } }];

        expect(await useCreatorActions().createListWith(company, 'Studios')).toBe(true);

        expect(calls.map((call) => [call.url, call.options.body])).toEqual([
            ['/ui.lists.store', { title: 'Studios' }],
            ['/ui.companies.lists.update/tmdb/3', { list_ids: [4, 9] }],
        ]);
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: expect.arrayContaining(['userLists']) }));
    });

    it('stops at a list that could not be created', async () => {
        answers = [{ status: 422, body: {} }];

        expect(await useCreatorActions().createListWith(company, '')).toBe(false);
        expect(calls).toHaveLength(1);
    });

    it("sets a collection's bell, reloading what the caller names", async () => {
        await useCreatorActions().followCollection('tmdb', '2344', 'mute', ['list']);

        expect(calls[0]).toMatchObject({ url: '/ui.collections.follow.update/tmdb/2344', options: { method: 'PUT', body: { mode: 'mute' } } });
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['list'] }));
    });
});

describe('the store following the page', () => {
    it("keeps a creator's answered state when a reload brings other props", async () => {
        setPageProps({ userStates: {}, creatorStates: { 'company:tmdb:3': { followed: false, listIds: [] } } });
        answers = [{ status: 200, body: { creatorStates: { 'company:tmdb:3': { followed: true, listIds: [] } } } }];

        await useCreatorActions().toggleFollow(company);

        // A list page reloads its `userStates` after a write; the page-load
        // `creatorStates` object rides along untouched.
        reloadProps({ userStates: {} });

        expect(useCreatorState(company).value.followed).toBe(true);
    });

    it('takes in creator states a reload does bring', () => {
        setPageProps({ creatorStates: { 'company:tmdb:3': { followed: false, listIds: [] } } });
        reloadProps({ creatorStates: { 'company:tmdb:3': { followed: false, listIds: [5] } } });

        expect(useCreatorState(company).value.listIds).toEqual([5]);
    });
});
