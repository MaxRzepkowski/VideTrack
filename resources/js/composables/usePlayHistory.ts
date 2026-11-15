import { jsonFetch } from '@/lib/http';
import type { WatchCycleSummary } from '@/types/generated';
import type { MediaSummary, PlayEntry } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * What the watched modal lists under a title: its plays (completions and
 * drops) and, on a show that was started over, its viewing cycles. Read
 * through API v1 for whichever title the modal holds when the read starts;
 * an answer that lands after the modal moved on to another title is dropped.
 */
export function usePlayHistory(media: () => MediaSummary | null) {
    const plays = ref<PlayEntry[]>([]);
    const cycles = ref<WatchCycleSummary[]>([]);

    // An empty list here would read as "no history", so a failed read says so.
    function failed(): null {
        toast.error(trans('common.something_went_wrong_please_try_again'));

        return null;
    }

    async function read<T>(name: string): Promise<T[] | null> {
        const current = media();

        if (!current) {
            return null;
        }

        let response: Response;

        try {
            response = await jsonFetch(route(name, { type: current.type, ref: current.ref }));
        } catch (error) {
            // Only a request that never got an answer (offline, the server
            // down) is the user's news; anything else is a bug.
            if (!(error instanceof TypeError)) {
                throw error;
            }

            return failed();
        }

        if (!response.ok) {
            return failed();
        }

        const { data } = (await response.json()) as { data: T[] };

        return media() === current ? data : null;
    }

    async function loadPlays(): Promise<void> {
        const loaded = await read<PlayEntry>('ui.media.plays.index');

        if (loaded) {
            plays.value = loaded;
        }
    }

    async function loadCycles(): Promise<void> {
        const loaded = await read<WatchCycleSummary>('ui.media.cycles.index');

        if (loaded) {
            cycles.value = loaded;
        }
    }

    function clear(): void {
        plays.value = [];
        cycles.value = [];
    }

    return { plays, cycles, loadPlays, loadCycles, clear };
}
