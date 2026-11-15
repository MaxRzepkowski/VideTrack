import type { Creator, CreatorState } from '@/lib/creators';
import { creatorState } from '@/stores/library';
import { computed, toValue, type ComputedRef, type MaybeRefOrGetter } from 'vue';

/**
 * A creator's bell and lists, from the library store — which takes them from
 * the page's `creatorStates` and from the answer to every write, so a card
 * shows its new state without the page reloading anything.
 */
export function useCreatorState(creator: MaybeRefOrGetter<Creator>): ComputedRef<CreatorState> {
    return computed(() => creatorState(toValue(creator)));
}
