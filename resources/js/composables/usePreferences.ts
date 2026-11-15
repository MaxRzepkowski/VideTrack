import { signedInUser } from '@/lib/pageContext';
import type { UserPreferences } from '@/types';
import { computed, type ComputedRef } from 'vue';

/**
 * The signed-in user's settings, every one filled in by the server — read
 * here rather than by reaching into the page props and guessing a default.
 */
export function usePreferences(): ComputedRef<UserPreferences> {
    return computed(() => signedInUser().preferences);
}
