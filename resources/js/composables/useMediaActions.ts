import { jsonFetch, type JsonFetchOptions } from '@/lib/http';
import { backlogOf } from '@/lib/lists';
import { mediaTypeDefinition } from '@/lib/mediaTypes';
import { nextFollowMode } from '@/lib/tracking';
import { absorbUserStates, displayedState, isBusyKey, settledState, stateKey, writeState } from '@/stores/library';
import type { SharedData } from '@/types';
import type { UserMediaState } from '@/types/generated';
import type { MediaPosition, MediaSummary, UserStatesMap, WatchedAtChoice } from '@/types/media';
import { usePage } from '@inertiajs/vue3';
import { watch, type ComputedRef, type MaybeRefOrGetter } from 'vue';

export { absorbUserStates, shownState, stateKey, useCardBusy } from '@/stores/library';

/** Absorb a page's deferred `deferredUserStates` prop each time it lands. */
export function useDeferredUserStates(states: () => UserStatesMap | undefined): void {
    watch(states, (value) => value && absorbUserStates(value), { immediate: true });
}

/**
 * What a user can do to a title, each action one call of API v1 (through the
 * session, `ui/v1`). The state on screen comes from the library store, which
 * takes it from the server's answer — no action predicts its outcome.
 */
export function useMediaActions() {
    const page = usePage<SharedData>();

    // Accepts a getter so a long-lived component (e.g. an Up Next card reused
    // for the next episode) tracks the summary it currently renders, not the
    // one it mounted with.
    const stateOf = (media: MaybeRefOrGetter<MediaSummary>): ComputedRef<UserMediaState> => displayedState(media);

    /** Containers carry episode counters; playables do not. */
    const isPlayable = (media: MediaSummary): boolean => mediaTypeDefinition(media.type).playable;

    const target = (media: MediaPosition) => ({ type: media.type, ref: media.ref });

    const send = (media: MediaPosition, name: string, options: JsonFetchOptions): Promise<boolean> =>
        writeState(media, () => jsonFetch(route(name, target(media)), options));

    /**
     * @param options.amount the session's hours or the counter's position
     * @param options.newAttempt the viewing opens another run instead of extending this one
     * @param options.mergeHours hours land on the open session rather than a new one
     */
    const markWatched = (
        media: MediaPosition,
        watchedAt: WatchedAtChoice,
        { amount = null, newAttempt = false, mergeHours = true }: { amount?: number | null; newAttempt?: boolean; mergeHours?: boolean } = {},
    ): void => {
        void send(media, 'ui.media.plays.store', {
            method: 'POST',
            body: { watched_at: watchedAt, amount, new_attempt: newAttempt, merge_hours: mergeHours },
        });
    };

    const watchedOrGivenStatus = (media: MediaPosition): boolean => {
        const state = settledState(stateKey(media));

        return state.status !== null || state.watched;
    };

    /**
     * The right-click shortcut: a title with no status that is not watched yet
     * is marked watched now. Read when the request would go out, so a second
     * quick click finds the first one landed and adds no extra viewing. With
     * nothing to send and no write under way, the click puts up no loader:
     * a card that spins and comes back unchanged reads as a failed save.
     */
    const markWatchedIfUntouched = (media: MediaPosition): void => {
        if (!isBusyKey(stateKey(media)) && watchedOrGivenStatus(media)) {
            return;
        }

        void writeState(media, async () => {
            if (watchedOrGivenStatus(media)) {
                return null;
            }

            return jsonFetch(route('ui.media.plays.store', target(media)), {
                method: 'POST',
                body: { watched_at: 'now', amount: null, new_attempt: false, merge_hours: true },
            });
        });
    };

    /**
     * Start a container, or carry on with it: the play lands on the episode
     * next in line, not on the whole run. Which episode that is stays a server
     * question, asked when the request goes out — the client holds a show, a
     * title it has never touched carries no hierarchy at all, and two quick
     * clicks must log two episodes, not the same one twice.
     */
    const markNextEpisodeWatched = (media: MediaSummary, watchedAt: WatchedAtChoice): void => {
        void send(media, 'ui.media.plays.store', { method: 'POST', body: { watched_at: watchedAt, next_episode: true } });
    };

    /**
     * Session progress on flat progress types (games): `add` appends a
     * session, `set` overwrites the counter from the progress editor.
     */
    const logProgress = (media: MediaSummary, change: { add?: number; set?: number }): void => {
        void send(media, 'ui.media.progress.update', { method: 'PUT', body: change });
    };

    const unmarkWatched = (media: MediaSummary): void => {
        void send(media, 'ui.media.plays.destroy', { method: 'DELETE' });
    };

    /** Start a container over: nothing is deleted, the counters start from zero. */
    const startRewatch = (media: MediaSummary): void => {
        void send(media, 'ui.media.cycles.store', { method: 'POST' });
    };

    /**
     * Undo the newest reset: the plays it collected are deleted and the run
     * before it stands as it did. Destructive by design:
     * the modal asks once, saying how many viewings go.
     */
    const cancelRewatch = (media: MediaSummary): void => {
        void send(media, 'ui.media.cycles.destroy', { method: 'DELETE' });
    };

    /** Correct the recorded cost of one history entry (games: minutes). */
    const updatePlay = (media: MediaSummary, playId: number, amount: number | null): Promise<boolean> =>
        writeState(media, () => jsonFetch(route('ui.plays.update', { play: playId }), { method: 'PATCH', body: { amount } }));

    /** Remove one history entry, not the whole title. */
    const deletePlay = (media: MediaSummary, playId: number): Promise<boolean> =>
        writeState(media, () => jsonFetch(route('ui.plays.destroy', { play: playId }), { method: 'DELETE' }));

    const rate = (media: MediaSummary, rating: number | null): void => {
        void (rating === null
            ? send(media, 'ui.media.rating.destroy', { method: 'DELETE' })
            : send(media, 'ui.media.rating.update', { method: 'PUT', body: { rating } }));
    };

    const setFavorite = (media: MediaSummary, favorite: boolean): void => {
        void send(media, 'ui.media.favorite.update', { method: 'PUT', body: { favorite } });
    };

    // The toggles below read the state they flip when their request goes out,
    // after the title's earlier writes have landed: two quick clicks flip
    // twice instead of sending the same picture twice.

    const toggleFavorite = (media: MediaSummary): void => {
        void writeState(media, () =>
            jsonFetch(route('ui.media.favorite.update', target(media)), {
                method: 'PUT',
                body: { favorite: !settledState(stateKey(media)).favorite },
            }),
        );
    };

    /** Bell exception on a hierarchy root: 'follow' forces, 'mute' silences, null hands back to the matrix. */
    const setFollow = (media: MediaSummary, mode: UserMediaState['follow']): void => {
        void send(media, 'ui.media.follow.update', { method: 'PUT', body: { mode } });
    };

    /** One click on the bell walks its cycle ({@see nextFollowMode}). */
    const cycleFollow = (media: MediaSummary): void => {
        void writeState(media, () =>
            jsonFetch(route('ui.media.follow.update', target(media)), {
                method: 'PUT',
                body: { mode: nextFollowMode(settledState(stateKey(media))) },
            }),
        );
    };

    /** Puts the title on one list or takes it off, its other memberships staying; reloads what it names after. */
    const putOnList = (media: MediaSummary, listId: number, reload: string[]): void => {
        void writeState(
            media,
            () => {
                const ids = settledState(stateKey(media)).listIds;

                return jsonFetch(route('ui.media.lists.update', target(media)), {
                    method: 'PUT',
                    body: { list_ids: ids.includes(listId) ? ids.filter((id) => id !== listId) : [...ids, listId] },
                });
            },
            reload,
        );
    };

    /**
     * A tick in the lists dialog. The dialog counts what each list holds, and
     * only the server knows that, so the tick reloads the lists too.
     */
    const toggleList = (media: MediaSummary, listId: number): void => putOnList(media, listId, ['userLists']);

    /**
     * Quick toggle of the system watchlist alone, from a card: no count is on
     * screen there, and a reload would keep the card's loader up for nothing.
     */
    const toggleWatchlist = (media: MediaSummary): void => {
        const watchlist = backlogOf(page.props.userLists);

        if (watchlist) {
            putOnList(media, watchlist.id, []);
        }
    };

    /**
     * Quick-create from the lists modal: the list, then the title on it, under
     * one loader. The new list reaches the modal with the user's lists, which
     * the page reloads once the title is on it.
     */
    const createListWithItem = (media: MediaSummary, title: string): void => {
        void writeState(media, async () => {
            const created = await jsonFetch(route('ui.lists.store'), { method: 'POST', body: { title } });

            if (!created.ok) {
                return created;
            }

            const { data } = (await created.json()) as { data: { id: number } };
            const listIds = [...settledState(stateKey(media)).listIds, data.id];

            return jsonFetch(route('ui.media.lists.update', target(media)), { method: 'PUT', body: { list_ids: listIds } });
        }, ['userLists']);
    };

    /**
     * Dropping is a dated history entry: it can carry the hours sunk into the
     * attempt (progress types) and the moment it happened ('now' | 'release' |
     * ISO date — same convention as logging a play). Null clears the status,
     * which resumes the attempt.
     */
    const setStatus = (media: MediaSummary, status: UserMediaState['status'], amount?: number | null, droppedAt?: WatchedAtChoice): void => {
        void send(media, 'ui.media.status.update', {
            method: 'PUT',
            body: { status, amount: amount ?? null, dropped_at: droppedAt ?? null },
        });
    };

    /**
     * Throw the open attempt away — the status and every session logged into
     * it. Distinct from clearing the status, which keeps them.
     */
    const discardProgress = (media: MediaSummary): void => {
        void send(media, 'ui.media.status.update', {
            method: 'PUT',
            body: { status: null, amount: null, dropped_at: null, discard_progress: true },
        });
    };

    return {
        stateOf,
        isPlayable,
        markWatched,
        markWatchedIfUntouched,
        markNextEpisodeWatched,
        unmarkWatched,
        startRewatch,
        cancelRewatch,
        updatePlay,
        deletePlay,
        rate,
        setFavorite,
        toggleFavorite,
        setFollow,
        cycleFollow,
        toggleList,
        toggleWatchlist,
        createListWithItem,
        setStatus,
        discardProgress,
        logProgress,
    };
}
