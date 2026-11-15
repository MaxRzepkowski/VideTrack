import { settledState, shownState, stateKey } from '@/stores/library';
import type { EpisodeSegment, MediaSummary, UpNextEntry } from '@/types/media';
import { computed, onScopeDispose, ref, watch, watchEffect, type ComputedRef } from 'vue';

/** How long a rated card stays up as confirmation before it retires. */
const RETIRE_DELAY = 1200;

/** A show that ran out of episodes on this page — the card offered in place of its last entry. */
export type CompletedShow = {
    show: MediaSummary;
    watchedEpisodes: number;
    airedEpisodes: number;
    /** Carried over from the Up Next entry, for the bar's hover hint. */
    skippedEpisodes: number;
    watchedMinutes: number;
    segments: EpisodeSegment[];
};

/** What the episode this card was pointing at costs, priced as the server prices it. */
const episodeMinutes = (entry: UpNextEntry): number =>
    entry.episode.totalRuntime ?? (entry.remainingEpisodes > 0 ? Math.round(entry.remainingMinutes / entry.remainingEpisodes) : 0);

/**
 * The entry's own bar with one episode moved to its settled state — the bar
 * the next server render would draw. Runs are re-merged, so settling the
 * episode between two watched runs leaves one run, not three.
 */
const settle = (segments: EpisodeSegment[], position: number, state: EpisodeSegment['state']): EpisodeSegment[] => {
    const split: EpisodeSegment[] = [];
    let covered = 0;

    segments.forEach((segment) => {
        const first = covered + 1;
        covered += segment.episodes;

        if (position < first || position > covered) {
            split.push({ ...segment });

            return;
        }

        if (position > first) {
            split.push({ state: segment.state, episodes: position - first });
        }

        split.push({ state, episodes: 1 });

        if (position < covered) {
            split.push({ state: segment.state, episodes: covered - position });
        }
    });

    return split.reduce<EpisodeSegment[]>((runs, segment) => {
        const last = runs[runs.length - 1];

        if (last?.state === segment.state) {
            return [...runs.slice(0, -1), { state: last.state, episodes: last.episodes + segment.episodes }];
        }

        return [...runs, segment];
    }, []);
};

/**
 * Settling a finale drops the show from Up Next, which is the right thing for
 * a "what to watch next" list but leaves the user with nothing to click at the
 * one moment they are most likely to want to rate what they just finished.
 * This puts a completion card in its place for the rest of the visit.
 *
 * Nothing is persisted: a reload or any navigation rebuilds the section from
 * the server and the card is gone. Which shows qualify is the backend's call
 * (`closes`) — running out mid-season is not a finish and stays silent, and a
 * show already rated gets no card, since the card has nothing to offer it.
 */
export function useCompletedShows(source: () => UpNextEntry[], enabled: () => boolean): ComputedRef<CompletedShow[]> {
    const completed = ref(new Map<string, CompletedShow>());

    // Ratings seen while the entry was still served. Once the finale is
    // settled the show leaves the payload with its state, so "was this
    // already rated" can only be answered from the last sighting — including
    // the very first one, before any watching happens on this page.
    let seenRatings = new Map<string, number | null>();

    const remember = (entries: UpNextEntry[]): void => {
        seenRatings = new Map(
            entries.filter((entry) => entry.closes !== null).map((entry) => [stateKey(entry.show), shownState(stateKey(entry.show)).rating]),
        );
    };

    let previous: UpNextEntry[] = source();

    remember(previous);

    // Keys already counting down, so the timer is armed once per card.
    const retiring = new Set<string>();
    const timers: ReturnType<typeof setTimeout>[] = [];

    const sync = (entries: UpNextEntry[]): void => {
        if (entries === previous) {
            return;
        }

        const live = new Set(entries.map((entry) => stateKey(entry.show)));

        previous.forEach((entry) => {
            const key = stateKey(entry.show);

            if (live.has(key) || completed.value.has(key) || entry.closes === null || seenRatings.get(key) != null) {
                return;
            }

            // Everything aired is settled now — but settling the last gap by
            // skipping it is not watching it, so the card reads the episode's
            // own state rather than assuming a play. The settled one: the
            // write that played the finale still holds its card under the
            // loader, picturing the episode as it was before.
            const watched = settledState(stateKey(entry.episode)).watched;

            completed.value.set(key, {
                show: entry.show,
                watchedEpisodes: entry.watchedEpisodes + (watched ? 1 : 0),
                airedEpisodes: entry.airedEpisodes,
                skippedEpisodes: entry.skippedEpisodes + (watched ? 0 : 1),
                // The last episode's runtime lands on the total the same way
                // the next server render would add it. Where the provider left
                // it blank, what the server charged for the episodes still
                // waiting is exactly the price it would charge for this one.
                watchedMinutes: entry.watchedMinutes + (watched ? episodeMinutes(entry) : 0),
                segments: settle(entry.segments, entry.position, watched ? 'watched' : 'skipped'),
            });
        });

        // A show the server serves again (a new episode aired, or the user
        // undid the play) drops its card instead of sitting beside itself.
        live.forEach((key) => completed.value.delete(key));

        remember(entries);

        previous = entries;
    };

    // Rating the show is what the card exists for, so it retires once given —
    // but not on the same tick: the score stays up for a beat, otherwise the
    // card vanishes mid-click and nothing confirms the rating landed.
    watchEffect(() => {
        completed.value.forEach((show, key) => {
            if (retiring.has(key) || shownState(stateKey(show.show)).rating === null) {
                return;
            }

            retiring.add(key);
            timers.push(setTimeout(() => completed.value.delete(key), RETIRE_DELAY));
        });
    });

    onScopeDispose(() => timers.forEach(clearTimeout));

    // Synchronously, so the cards are in step the moment the entries change —
    // the next read already sees the show that just ran out.
    watch(source, sync, { flush: 'sync' });

    return computed(() => (enabled() ? [...completed.value.values()].reverse() : []));
}
