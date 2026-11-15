import { absorbUserStates } from '@/composables/useMediaActions';
import { jsonFetch } from '@/lib/http';
import type { CreditPage } from '@/types/generated';
import type { CompanyCredit, UserStatesMap } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch, type ComputedRef, type Ref } from 'vue';
import { toast } from 'vue-sonner';

type Chunk = CreditPage & { userStates: UserStatesMap };

/** Role, shelf and title together: one company can both develop and publish a game. */
const keyOf = (credit: CompanyCredit): string => `${credit.role}:${credit.category}:${credit.media.type}:${credit.media.ref}`;

/**
 * A creator's catalogue, streamed. Providers pay out their catalogue in pages —
 * TMDB twenty titles at a time, and Warner Bros has over three thousand — so a
 * company or network page opens on the first chunk and pulls the next ones on
 * demand, appending instead of replacing.
 *
 * A plain fetch, never an Inertia visit: a visit would repaint the whole page
 * and throw away what is shown.
 *
 * @param identity  who the catalogue belongs to; changing it starts a new stream
 * @param first  the chunk that came with the page render
 * @param initialHasMore  whether the provider holds more behind it
 * @param url  the endpoint for chunk `page` (2 and up)
 */
export function useStreamedCredits(
    identity: () => string,
    first: () => CompanyCredit[],
    initialHasMore: () => boolean,
    url: (page: number) => string,
): {
    credits: ComputedRef<CompanyCredit[]>;
    hasMore: ComputedRef<boolean>;
    loading: Ref<boolean>;
    loadMore: () => Promise<void>;
} {
    const streamed = ref<CompanyCredit[]>([]);
    const nextPage = ref(2);
    const exhausted = ref(false);
    const loading = ref(false);

    // Navigating from one company to another reuses the component; the new
    // catalogue must not inherit the old one's chunks. Keyed on who the page
    // is about, not on the first chunk itself: every write on the page (a bell,
    // a rating) brings fresh props, and a fresh array would drop the stream.
    watch(identity, () => {
        streamed.value = [];
        nextPage.value = 2;
        exhausted.value = false;
    });

    const credits = computed(() => [...first(), ...streamed.value]);
    const hasMore = computed(() => !exhausted.value && initialHasMore());

    async function loadMore(): Promise<void> {
        if (loading.value || !hasMore.value) {
            return;
        }

        loading.value = true;

        // A chunk that lands after the page moved on to another creator
        // belongs to the catalogue that is gone.
        const owner = identity();

        try {
            const response = await jsonFetch(url(nextPage.value));

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const chunk = (await response.json()) as Chunk;

            if (identity() !== owner) {
                return;
            }

            absorbUserStates(chunk.userStates);

            // Offsetting a rating sort over titles that tie can hand back one
            // the page already holds — keep the copy that arrived first.
            const seen = new Set(credits.value.map(keyOf));

            streamed.value = [...streamed.value, ...chunk.credits.filter((credit) => !seen.has(keyOf(credit)))];
            exhausted.value = !chunk.hasMore;
            nextPage.value += 1;
        } catch {
            // The catalogue stops here rather than offering a button that fails
            // again on every press.
            if (identity() === owner) {
                exhausted.value = true;
                toast.error(trans('common.something_went_wrong_please_try_again'));
            }
        } finally {
            loading.value = false;
        }
    }

    return { credits, hasMore, loading, loadMore };
}
