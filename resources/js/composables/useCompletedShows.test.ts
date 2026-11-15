import { useCompletedShows } from '@/composables/useCompletedShows';
import { writeState } from '@/stores/library';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import { nth } from '@/test/lists';
import { setPageProps, testPage } from '@/test/page';
import type { UpNextEntry, UserStatesMap } from '@/types/media';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';

function entry(show: string, overrides: Partial<UpNextEntry> = {}): UpNextEntry {
    return {
        show: mediaSummary({ type: 'show', ref: show, title: `Show ${show}` }),
        episode: mediaSummary({ type: 'episode', ref: `${show}:1:10`, totalRuntime: 50 }),
        poster: null,
        badge: null,
        closes: 'series',
        watchedEpisodes: 9,
        skippedEpisodes: 0,
        airedEpisodes: 10,
        remainingEpisodes: 1,
        remainingMinutes: 50,
        watchedMinutes: 450,
        position: 10,
        segments: [
            { state: 'watched', episodes: 9 },
            { state: 'pending', episodes: 1 },
        ],
        ...overrides,
    };
}

const states = (map: UserStatesMap) => (testPage.props.userStates = map);

beforeEach(() => {
    useMediaTypes();
    vi.useFakeTimers();
    setPageProps({ userStates: {} });
});

afterEach(() => vi.useRealTimers());

function track(entries: UpNextEntry[], enabled = true) {
    const source = ref(entries);
    const scope = effectScope();
    const completed = scope.run(() =>
        useCompletedShows(
            () => source.value,
            () => enabled,
        ),
    )!;

    return { source, completed, scope };
}

describe('useCompletedShows', () => {
    it('offers a card for a show that ran out of episodes, the finale counted as watched', () => {
        const { source, completed } = track([entry('a'), entry('b')]);
        expect(completed.value).toEqual([]);

        states({ 'episode:a:1:10': userState({ watched: true }) });
        source.value = [entry('b')];

        expect(completed.value).toHaveLength(1);
        expect(completed.value[0]).toMatchObject({
            watchedEpisodes: 10,
            skippedEpisodes: 0,
            watchedMinutes: 500,
            segments: [{ state: 'watched', episodes: 10 }],
        });
    });

    it('counts the finale as watched when the show drops out while its write is still under the loader', async () => {
        const { source, completed } = track([entry('a')]);
        const answer = new Response(JSON.stringify({ states: { 'episode:a:1:10': userState({ watched: true }) } }));

        await writeState(entry('a').episode, () => Promise.resolve(answer));
        // The loader's minimum is still running: the card pictures the episode as unwatched.
        source.value = [];

        expect(completed.value[0]).toMatchObject({ watchedEpisodes: 10, skippedEpisodes: 0 });
        await vi.runAllTimersAsync();
    });

    it('counts a finale settled by a skip as skipped, not watched', () => {
        const { source, completed } = track([entry('a')]);

        source.value = [];

        expect(completed.value[0]).toMatchObject({ watchedEpisodes: 9, skippedEpisodes: 1, watchedMinutes: 450 });
        expect(nth(completed.value, 0).segments).toEqual([
            { state: 'watched', episodes: 9 },
            { state: 'skipped', episodes: 1 },
        ]);
    });

    it('merges the settled episode with the watched runs either side of it', () => {
        const gap = entry('a', {
            position: 5,
            segments: [
                { state: 'watched', episodes: 4 },
                { state: 'pending', episodes: 1 },
                { state: 'watched', episodes: 5 },
            ],
        });
        const { source, completed } = track([gap]);

        states({ 'episode:a:1:10': userState({ watched: true }) });
        source.value = [];

        expect(nth(completed.value, 0).segments).toEqual([{ state: 'watched', episodes: 10 }]);
    });

    it('stays silent for a show that only ran out mid-season, or was rated already', () => {
        states({ 'show:r': userState({ rating: 8 }) });
        const { source, completed } = track([entry('a', { closes: null }), entry('r')]);

        source.value = [];

        expect(completed.value).toEqual([]);
    });

    it('drops the card when the show comes back, and shows nothing when the section is off', () => {
        const { source, completed } = track([entry('a')]);
        source.value = [];
        expect(completed.value).toHaveLength(1);

        source.value = [entry('a', { watchedEpisodes: 10, airedEpisodes: 11 })];
        expect(completed.value).toEqual([]);

        const off = track([entry('c')], false);
        off.source.value = [];
        expect(off.completed.value).toEqual([]);
    });

    it('retires a card a beat after the show is rated', async () => {
        const { source, completed, scope } = track([entry('a')]);
        source.value = [];
        expect(completed.value).toHaveLength(1);

        states({ 'show:a': userState({ rating: 9 }) });
        await nextTick();
        vi.advanceTimersByTime(1199);
        expect(completed.value).toHaveLength(1);

        vi.advanceTimersByTime(1);
        expect(completed.value).toEqual([]);
        scope.stop();
    });

    it('prices an episode without its own runtime at what the rest costs', () => {
        const { source, completed } = track([
            entry('a', { episode: mediaSummary({ type: 'episode', ref: 'a:1:10', totalRuntime: null }), remainingEpisodes: 2, remainingMinutes: 90 }),
        ]);
        states({ 'episode:a:1:10': userState({ watched: true }) });

        source.value = [];

        expect(nth(completed.value, 0).watchedMinutes).toBe(495);
    });
});
