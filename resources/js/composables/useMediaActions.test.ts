import { absorbUserStates, stateKey, useCardBusy, useDeferredUserStates, useMediaActions } from '@/composables/useMediaActions';
import { useReloadAfterWrites } from '@/composables/useReloadAfterWrites';
import type { JsonFetchOptions } from '@/lib/http';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import { nth } from '@/test/lists';
import { reloadProps, setPageProps, visitPage } from '@/test/page';
import { withSetup } from '@/test/withSetup';
import type { UserStatesMap } from '@/types/media';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';

type Call = { url: string; method: string; body: unknown; answer: (status: number, body?: unknown) => Promise<void> };

const calls: Call[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, { method = 'GET', body }: JsonFetchOptions = {}) =>
        new Promise<Response>((resolve) => {
            calls.push({
                url,
                method,
                body,
                // Resolves once the writer has taken the answer in.
                answer: async (status, payload = {}) => {
                    resolve(new Response(JSON.stringify(payload), { status }));
                    await vi.advanceTimersByTimeAsync(0);
                },
            });
        }),
}));

let reloads: { only: string[]; onFinish: () => void }[] = [];

beforeEach(() => {
    useMediaTypes();
    vi.useFakeTimers({ now: new Date('2026-10-10T12:00:00Z') });
    setPageProps({ userLists: [{ id: 5, systemKey: 'watchlist', itemCount: 0 }] });
    calls.length = 0;
    reloads = [];
    vi.spyOn(router, 'reload').mockImplementation(((options: { only: string[]; onFinish: () => void }) => {
        reloads.push(options);
    }) as never);
});

afterEach(async () => {
    // Every write lands, so no title stays under a loader into the next test.
    for (const call of calls) {
        await call.answer(200, {});
    }

    reloads.forEach((reload) => reload.onFinish());
    await vi.runAllTimersAsync();
    vi.useRealTimers();
});

const movie = (ref = 'm1') => mediaSummary({ type: 'movie', ref, releaseDate: '2026-01-02' });
const show = (ref = 's1') => mediaSummary({ type: 'show', ref });
const game = (ref = 'g1') => mediaSummary({ type: 'game', ref });

/** A page that reloads these props of its own after every write. */
function pageReloading(only: string[]) {
    return mount(defineComponent({ setup: () => useReloadAfterWrites(only), template: '<div />' }));
}

/** The lists a tick in the lists dialog reloaded come back. */
async function listsReloaded(): Promise<void> {
    await vi.advanceTimersByTimeAsync(0);
    reloads.filter((reload) => reload.only.length === 1 && reload.only[0] === 'userLists').forEach((reload) => reload.onFinish());
    await vi.advanceTimersByTimeAsync(300);
}

/**
 * The write lands with these states; the lists it reloads (a tick in the lists
 * dialog asks for them) come back with it; the loader's minimum then runs out.
 */
async function land(states: UserStatesMap, call = calls.at(-1)): Promise<void> {
    await call?.answer(200, { data: {}, states });
    await listsReloaded();
}

describe('reading a state', () => {
    it("is the server's: the untouched state, then the page's, then a streamed row's", () => {
        const { stateOf } = useMediaActions();

        expect(stateOf(movie('a')).value).toEqual(userState());

        setPageProps({ userStates: { 'movie:a': userState({ rating: 9 }) } });
        expect(stateOf(movie('a')).value.rating).toBe(9);

        absorbUserStates({ 'movie:b': userState({ favorite: true }) });
        expect(stateOf(movie('b')).value.favorite).toBe(true);
    });

    it('takes in a deferred section of states whenever it lands', async () => {
        const deferred = ref<UserStatesMap | undefined>(undefined);

        withSetup(() => useDeferredUserStates(() => deferred.value));
        deferred.value = { 'movie:c': userState({ rating: 6 }) };
        await nextTick();

        expect(useMediaActions().stateOf(movie('c')).value.rating).toBe(6);
    });

    it('forgets what the old page held on leaving it', () => {
        absorbUserStates({ 'movie:b': userState({ favorite: true }) });
        visitPage();

        expect(useMediaActions().stateOf(movie('b')).value.favorite).toBe(false);
    });

    it('keys a state by type and ref', () => {
        expect(stateKey(show('42'))).toBe('show:42');
    });
});

describe('a write', () => {
    it('holds the old picture under the loader, then shows what the server answered', async () => {
        const actions = useMediaActions();
        const state = actions.stateOf(movie());
        const busy = useCardBusy(movie());

        actions.setFavorite(movie(), true);
        await nextTick();
        expect(busy.value).toBe(true);

        await nth(calls, 0).answer(200, { states: { 'movie:m1': userState({ favorite: true }) } });
        expect(state.value.favorite).toBe(false);

        vi.advanceTimersByTime(300);
        expect(busy.value).toBe(false);
        expect(state.value.favorite).toBe(true);
    });

    it('takes in every state the write touched — the containers above the title too', async () => {
        const actions = useMediaActions();
        const episode = mediaSummary({ type: 'episode', ref: 's1:1:1' });

        actions.markWatched(episode, 'now');
        await land({
            'episode:s1:1:1': userState({ watched: true }),
            'season:s1:1': userState({ watchedEpisodes: 1 }),
            'show:s1': userState({ watchedEpisodes: 1, inProgress: true }),
        });

        expect(actions.stateOf(show()).value).toMatchObject({ watchedEpisodes: 1, inProgress: true });
        expect(actions.stateOf(mediaSummary({ type: 'season', ref: 's1:1' })).value.watchedEpisodes).toBe(1);
    });

    it('says so when the server refuses, and the title stays as it was', async () => {
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);
        const actions = useMediaActions();

        actions.rate(movie(), 8);
        await nth(calls, 0).answer(422, { message: 'invalid' });
        vi.advanceTimersByTime(300);

        expect(error).toHaveBeenCalledWith('Something went wrong. Please try again.');
        expect(actions.stateOf(movie()).value.rating).toBeNull();
    });

    it('tells the user why when a rule of the library refuses it', async () => {
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);

        useMediaActions().markWatched(movie(), 'release');
        await nth(calls, 0).answer(422, { message: 'This title has no release date.', errors: { watched_at: ['This title has no release date.'] } });

        expect(error).toHaveBeenCalledWith('This title has no release date.');
    });

    it('reloads what the page said depends on the library, under the loader', async () => {
        const page = pageReloading(['upNext', 'userStates']);
        const busy = useCardBusy(movie());

        useMediaActions().markWatched(movie(), 'now');
        await land({});

        expect(reloads.map((reload) => reload.only)).toEqual([['upNext', 'userStates']]);
        expect(busy.value).toBe(true);

        nth(reloads, 0).onFinish();
        await vi.runAllTimersAsync();
        expect(busy.value).toBe(false);

        page.unmount();
        useMediaActions().rate(movie(), 7);
        await land({});
        expect(reloads).toHaveLength(1);
    });

    it("folds the page's props and the write's own into one reload", async () => {
        const page = pageReloading(['items', 'userStates']);

        useMediaActions().createListWithItem(movie(), 'Weekend');
        await nth(calls, 0).answer(201, { data: { id: 9 } });
        await land({});

        expect(reloads.map((reload) => reload.only)).toEqual([['userLists', 'items', 'userStates']]);
        page.unmount();
    });

    it('lets a write outlive its page: the answer is taken in, its loader and its own props stay behind', async () => {
        const actions = useMediaActions();

        actions.createListWithItem(movie(), 'Weekend');
        await nth(calls, 0).answer(201, { data: { id: 9 } });

        visitPage();
        const page = pageReloading(['upNext']);
        const busy = useCardBusy(movie());

        await nextTick();
        expect(busy.value).toBe(false);

        await nth(calls, 1).answer(200, { data: {}, states: { 'movie:m1': userState({ listIds: [9] }) } });
        expect(actions.stateOf(movie()).value.listIds).toEqual([9]);
        expect(reloads.map((reload) => reload.only)).toEqual([['upNext']]);

        page.unmount();
    });

    it('sends reloads one at a time, and writes landing meanwhile share the next one', async () => {
        const page = pageReloading(['upNext']);
        const actions = useMediaActions();

        actions.rate(movie('a'), 7);
        await land({});
        actions.rate(movie('b'), 7);
        await land({}, calls[1]);
        actions.rate(movie('c'), 7);
        await land({}, calls[2]);

        // An older reload landing last would put an older picture on the page.
        expect(reloads).toHaveLength(1);

        nth(reloads, 0).onFinish();
        await vi.advanceTimersByTimeAsync(0);
        expect(reloads.map((reload) => reload.only)).toEqual([['upNext'], ['upNext']]);
        page.unmount();
    });

    it('keeps what a write answered when a reload brings back props without userStates', async () => {
        setPageProps({ userStates: { 'movie:m1': userState({ listIds: [] }) } });
        const actions = useMediaActions();

        actions.toggleList(movie(), 3);
        await land({ 'movie:m1': userState({ listIds: [3] }) });

        // Inertia merges a partial reload into the props it had: the page-load
        // `userStates` object rides along untouched.
        reloadProps({
            userLists: [
                { id: 5, systemKey: 'watchlist', itemCount: 0 },
                { id: 3, systemKey: null, itemCount: 0 },
            ],
        });
        await nextTick();

        expect(actions.stateOf(movie()).value.listIds).toEqual([3]);
    });

    it('says so when the request never got an answer', async () => {
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);
        const { writeState } = await import('@/stores/library');

        expect(await writeState(movie(), () => Promise.reject(new TypeError('offline')))).toBe(false);
        expect(error).toHaveBeenCalledWith('Something went wrong. Please try again.');
    });
});

describe('the requests', () => {
    it('logs a play of a title', () => {
        useMediaActions().markWatched(movie(), 'release', { amount: null });

        expect(calls[0]).toMatchObject({
            method: 'POST',
            url: '/ui.media.plays.store/movie/m1',
            body: { watched_at: 'release', amount: null, new_attempt: false, merge_hours: true },
        });
    });

    it('starts a container at its next episode', () => {
        useMediaActions().markNextEpisodeWatched(show(), 'now');

        expect(calls[0]).toMatchObject({ method: 'POST', url: '/ui.media.plays.store/show/s1', body: { watched_at: 'now', next_episode: true } });
    });

    it('adds a game session or overwrites the counter', () => {
        const actions = useMediaActions();

        actions.logProgress(game(), { add: 30 });
        actions.logProgress(game('g2'), { set: 120 });

        expect(calls.map((call) => [call.method, call.url, call.body])).toEqual([
            ['PUT', '/ui.media.progress.update/game/g1', { add: 30 }],
            ['PUT', '/ui.media.progress.update/game/g2', { set: 120 }],
        ]);
    });

    it('removes plays, starts and cancels a rewatch', () => {
        const actions = useMediaActions();

        actions.unmarkWatched(show('s1'));
        actions.startRewatch(show('s2'));
        actions.cancelRewatch(show('s3'));

        expect(calls.map((call) => `${call.method} ${call.url}`)).toEqual([
            'DELETE /ui.media.plays.destroy/show/s1',
            'POST /ui.media.cycles.store/show/s2',
            'DELETE /ui.media.cycles.destroy/show/s3',
        ]);
    });

    it('corrects and removes one history entry by its own id', async () => {
        const actions = useMediaActions();

        const corrected = actions.updatePlay(game(), 12, 90);
        const removed = actions.deletePlay(game('g2'), 12);

        expect(calls.map((call) => [call.method, call.url, call.body])).toEqual([
            ['PATCH', '/ui.plays.update/12', { amount: 90 }],
            ['DELETE', '/ui.plays.destroy/12', undefined],
        ]);

        await land({}, calls[0]);
        await nth(calls, 1).answer(404);
        expect(await corrected).toBe(true);
        expect(await removed).toBe(false);
    });

    it('rates with a put and clears a rating with a delete', () => {
        const actions = useMediaActions();

        actions.rate(movie(), 7);
        actions.rate(movie('m2'), null);

        expect(calls[0]).toMatchObject({ method: 'PUT', url: '/ui.media.rating.update/movie/m1', body: { rating: 7 } });
        expect(calls[1]).toMatchObject({ method: 'DELETE', url: '/ui.media.rating.destroy/movie/m2' });
    });

    it('sets the favourite, the bell and the status', () => {
        const actions = useMediaActions();

        actions.setFavorite(movie(), true);
        actions.setFollow(show(), 'mute');
        actions.setStatus(game(), 'dropped', 90, 'now');
        actions.discardProgress(game('g2'));

        expect(calls.map((call) => [call.url, call.body])).toEqual([
            ['/ui.media.favorite.update/movie/m1', { favorite: true }],
            ['/ui.media.follow.update/show/s1', { mode: 'mute' }],
            ['/ui.media.status.update/game/g1', { status: 'dropped', amount: 90, dropped_at: 'now' }],
            ['/ui.media.status.update/game/g2', { status: null, amount: null, dropped_at: null, discard_progress: true }],
        ]);
    });

    it('toggles one list and keeps the others, and does nothing without a watchlist', async () => {
        setPageProps({ userStates: { 'movie:m1': userState({ listIds: [3] }) } });
        const actions = useMediaActions();

        actions.toggleWatchlist(movie());
        expect(nth(calls, 0).body).toEqual({ list_ids: [3, 5] });
        await land({ 'movie:m1': userState({ listIds: [3, 5] }) });

        actions.toggleList(movie(), 3);
        expect(nth(calls, 1).body).toEqual({ list_ids: [5] });
        await land({ 'movie:m1': userState({ listIds: [5] }) });

        setPageProps({ userLists: [] });
        actions.toggleWatchlist(movie());
        expect(calls).toHaveLength(2);
    });

    it('builds a second quick click from what the first one left, not from the picture under the loader', async () => {
        setPageProps({ userStates: { 'movie:m1': userState({ listIds: [] }) } });
        const actions = useMediaActions();

        actions.toggleList(movie(), 3);
        actions.toggleList(movie(), 4);
        actions.toggleFavorite(movie());
        actions.toggleFavorite(movie());

        // One request at a time per title: the second waits for the first.
        expect(calls).toHaveLength(1);

        await nth(calls, 0).answer(200, { states: { 'movie:m1': userState({ listIds: [3] }) } });
        await listsReloaded();
        expect(nth(calls, 1).body).toEqual({ list_ids: [3, 4] });

        await nth(calls, 1).answer(200, { states: { 'movie:m1': userState({ listIds: [3, 4] }) } });
        await listsReloaded();
        expect(nth(calls, 2).body).toEqual({ favorite: true });

        await nth(calls, 2).answer(200, { states: { 'movie:m1': userState({ listIds: [3, 4], favorite: true }) } });
        expect(nth(calls, 3).body).toEqual({ favorite: false });
    });

    it('walks the bell on twice and marks an untouched title once, however quick the clicks', async () => {
        const actions = useMediaActions();

        actions.cycleFollow(show());
        actions.cycleFollow(show());
        await nth(calls, 0).answer(200, { states: { 'show:s1': userState({ follow: 'follow', followSource: 'followed' }) } });
        expect(nth(calls, 1).body).toEqual({ mode: null });

        actions.markWatchedIfUntouched(movie());
        actions.markWatchedIfUntouched(movie());
        await nth(calls, 2).answer(200, { states: { 'movie:m1': userState({ watched: true }) } });
        await vi.advanceTimersByTimeAsync(0);

        expect(calls.map((call) => call.url)).toEqual([
            '/ui.media.follow.update/show/s1',
            '/ui.media.follow.update/show/s1',
            '/ui.media.plays.store/movie/m1',
        ]);
        expect(nth(calls, 2).body).toEqual({ watched_at: 'now', amount: null, new_attempt: false, merge_hours: true });
    });

    it('lets a refused write through to the next one', async () => {
        vi.spyOn(toast, 'error').mockImplementation(() => 1);
        const actions = useMediaActions();

        actions.toggleFavorite(movie());
        actions.toggleFavorite(movie());
        await nth(calls, 0).answer(422, { message: 'no' });

        expect(nth(calls, 1).body).toEqual({ favorite: true });
    });

    it('creates a list, puts the title on it and reloads the lists, all under one loader', async () => {
        setPageProps({ userStates: { 'movie:m1': userState({ listIds: [3] }) } });
        const busy = useCardBusy(movie());

        useMediaActions().createListWithItem(movie(), 'Weekend');
        await nextTick();
        expect(busy.value).toBe(true);
        expect(calls[0]).toMatchObject({ method: 'POST', url: '/ui.lists.store', body: { title: 'Weekend' } });

        await nth(calls, 0).answer(201, { data: { id: 9 } });
        expect(calls[1]).toMatchObject({ method: 'PUT', url: '/ui.media.lists.update/movie/m1', body: { list_ids: [3, 9] } });

        await nth(calls, 1).answer(200, { states: { 'movie:m1': userState({ listIds: [3, 9] }) } });
        expect(reloads.map((reload) => reload.only)).toEqual([['userLists']]);
    });

    it('reads the lists again when the list was made but the title could not join it', async () => {
        vi.spyOn(toast, 'error').mockImplementation(() => 1);

        useMediaActions().createListWithItem(movie(), 'Weekend');
        await nth(calls, 0).answer(201, { data: { id: 9 } });
        await nth(calls, 1).answer(500);

        expect(reloads.map((reload) => reload.only)).toEqual([['userLists']]);
    });

    it('stops at a refused list, putting the title nowhere', async () => {
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);

        useMediaActions().createListWithItem(movie(), 'Weekend');
        await nth(calls, 0).answer(422, { message: 'invalid' });

        expect(calls).toHaveLength(1);
        expect(error).toHaveBeenCalledWith('Something went wrong. Please try again.');
    });
});

describe('useCardBusy', () => {
    it('stays with the write it went up for, even when the card moves on', async () => {
        const current = ref(mediaSummary({ type: 'episode', ref: 's1:1:1' }));
        const busy = useCardBusy(() => current.value);

        useMediaActions().markWatched(current.value, 'now');
        await nextTick();
        current.value = mediaSummary({ type: 'episode', ref: 's1:1:2' });
        await nextTick();
        expect(busy.value).toBe(true);

        await land({});
        expect(busy.value).toBe(false);
    });
});
