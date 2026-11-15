import { reloadAfterWrites } from '@/stores/library';
import { onMounted, onUnmounted } from 'vue';

/**
 * A page whose own props depend on the library — Up Next, a panel's entries,
 * a show's seasons — names them here, and every write the user makes reloads
 * just those props, inside the write: the card's loader stays up until they
 * land, so an Up Next card moves to the next episode as the loader lifts.
 */
export function useReloadAfterWrites(only: string[]): void {
    let stop: (() => void) | null = null;

    onMounted(() => {
        stop = reloadAfterWrites(only);
    });

    onUnmounted(() => stop?.());
}
