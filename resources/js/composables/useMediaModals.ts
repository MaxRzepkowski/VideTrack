import type { Creator } from '@/lib/creators';
import type { WatchedTab } from '@/lib/watchedModal';
import type { MediaSummary } from '@/types/media';
import { reactive } from 'vue';

/** Whose lists the creator lists modal shows. */
export interface CreatorListsTarget {
    creator: Creator;
    name: string;
}

// The media modals (watched, rate, lists, a creator's lists) are mounted by
// the app layout, each the first time it is asked for; any action surface
// opens them for a given summary. The watched
// modal is the single status surface: completing, dropping, putting on
// hold, the attempt list AND the progress counter of a title in progress.
const modals = reactive<{
    watched: MediaSummary | null;
    /** Preselected view of the watched modal. */
    watchedTab: WatchedTab;
    rate: MediaSummary | null;
    lists: MediaSummary | null;
    creatorLists: CreatorListsTarget | null;
}>({
    watched: null,
    watchedTab: 'watched',
    rate: null,
    lists: null,
    creatorLists: null,
});

export function useMediaModals() {
    return {
        modals,
        openWatched: (media: MediaSummary, tab: WatchedTab = 'watched') => {
            modals.watchedTab = tab;
            modals.watched = media;
        },
        openRate: (media: MediaSummary) => (modals.rate = media),
        openLists: (media: MediaSummary) => (modals.lists = media),
        openCreatorLists: (creator: Creator, name: string) => (modals.creatorLists = { creator, name }),
        close: () => {
            modals.watched = null;
            modals.rate = null;
            modals.lists = null;
            modals.creatorLists = null;
        },
    };
}
