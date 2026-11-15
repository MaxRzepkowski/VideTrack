<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import SortControl from '@/components/SortControl.vue';
import { usePosterColumns, useShelfLimit, wholeRows } from '@/composables/useShelfLimit';
import { useStreamedCredits } from '@/composables/useStreamedCredits';
import { todayDateKey } from '@/lib/format';
import { kindPlural } from '@/lib/mediaTypes';
import { byRating, byRelease, byTitle } from '@/lib/titleSort';
import { SELECT_CLASS } from '@/lib/ui';
import type { CatalogueShelf, CreatorKind } from '@/types/generated';
import type { CompanyCredit, CompanyDetails, CompanyRole } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

// The titles of a studio, a publisher or a network, one role and one type at a time.
const props = defineProps<{
    provider: string;
    kind: Extract<CreatorKind, 'company' | 'network'>;
    company: CompanyDetails;
}>();

type Media = CompanyCredit['media'];

// The provider hands out its catalogue in chunks (Netflix alone carries
// thousands of shows): the page opens on the first one and pulls the rest on
// demand, so every shelf below reads `credits`, not the prop.
const {
    credits,
    hasMore: hasMoreCredits,
    loading: loadingMore,
    loadMore,
} = useStreamedCredits(
    () => `${props.kind}:${props.provider}:${props.company.ref}`,
    () => props.company.credits,
    () => props.company.hasMoreCredits,
    (page) => route(`${props.kind}.credits`, { provider: props.provider, ref: props.company.ref, page }),
);

// One shelf at a time, as a person's page shows one department and one type:
// the role picks what the company did (a game company develops, publishes, ports or supports, a
// film studio produces, a network airs), the type picks which of its titles
// (Games / DLC / Other for a game company, Movies / Shows for a film studio).
// Only what actually has titles is offered.
const ROLE_ORDER: CompanyRole[] = ['developer', 'publisher', 'porting', 'supporting', 'studio', 'network'];
const ROLE_LABELS: Record<CompanyRole, () => string> = {
    developer: () => trans('media.developed'),
    publisher: () => trans('media.published'),
    porting: () => trans('media.ported'),
    supporting: () => trans('media.supported'),
    studio: () => trans('media.produced'),
    network: () => trans('media.aired'),
};

const roles = computed(() => ROLE_ORDER.filter((r) => credits.value.some((cr) => cr.role === r)));
const selectedRole = ref<CompanyRole | null>(null);
const currentRole = computed(() => roles.value.find((role) => role === selectedRole.value) ?? roles.value[0] ?? null);

const CATEGORY_ORDER: CatalogueShelf[] = ['game', 'dlc', 'other', 'movie', 'show'];
// A shelf named after a media type reads as that type does everywhere else.
function shelfLabel(shelf: CatalogueShelf): string {
    switch (shelf) {
        case 'dlc':
            return trans('media.dlcs');
        case 'other':
            return trans('media.other');
        default:
            return kindPlural(shelf);
    }
}

// The types the active role has: the choice holds while the role has that
// type, else the first type does.
const categories = computed(() => CATEGORY_ORDER.filter((c) => credits.value.some((cr) => cr.role === currentRole.value && cr.category === c)));
const selectedCategory = ref<CatalogueShelf | null>(null);
const currentCategory = computed(() => categories.value.find((category) => category === selectedCategory.value) ?? categories.value[0] ?? 'game');

const shelf = computed(() => credits.value.filter((c) => c.role === currentRole.value && c.category === currentCategory.value).map((c) => c.media));

// Sorting shared by all sections — same control as the person page's credits.
// The provider serves titles rating-first, so that is the default.
type GameSort = 'rating' | 'release' | 'title';

const gameSort = ref<GameSort>('rating');
const gameDir = ref<'asc' | 'desc'>('desc');

watch(gameSort, () => (gameDir.value = gameSort.value === 'title' ? 'asc' : 'desc'));

const gameSortOptions = computed(() => [
    { value: 'rating', label: trans('common.community_rating') },
    { value: 'release', label: trans('common.release_date') },
    { value: 'title', label: trans('common.title') },
]);

const today = todayDateKey();
const isUpcoming = (m: Media) => (m.releaseDate ?? '') > today;

function sortGames(games: Media[]): Media[] {
    const dir = gameDir.value === 'asc' ? 1 : -1;

    return [...games].sort((a, b) => {
        switch (gameSort.value) {
            case 'release':
                return dir * byRelease(a, b);
            case 'title':
                return dir * byTitle(a, b);
            default:
                // Unreleased games carry no rating; rather than sink them below
                // the fold they lead as the company's "coming next", soonest
                // first — then the released catalogue by rating (the person
                // page surfaces anticipated upcoming credits the same way).
                if (isUpcoming(a) !== isUpcoming(b)) {
                    return isUpcoming(a) ? -1 : 1;
                }

                if (isUpcoming(a) && isUpcoming(b)) {
                    return byRelease(a, b);
                }

                return dir * byRating(a, b);
        }
    });
}

// The shelf opens on ~12 cards, rounded up to fill its last row; the rest of
// what the page holds sits behind the toggle, and what the provider still
// holds behind the same toggle once the shelf is open. Another role or type
// folds it again.
const GAMES_LIMIT = useShelfLimit(12);
const expanded = ref(false);

watch([currentRole, currentCategory], () => (expanded.value = false));

const columns = usePosterColumns();

// Sorted once per change rather than on every read.
const sortedShelf = computed(() => sortGames(shelf.value));

const visibleShelf = computed(() => {
    if (!expanded.value) {
        return sortedShelf.value.slice(0, GAMES_LIMIT.value);
    }

    // An opened shelf with more still to come stops at its last full row; the
    // remainder arrives with the next chunk rather than dangling above the button.
    return hasMoreCredits.value ? wholeRows(sortedShelf.value, columns.value) : sortedShelf.value;
});

const hidden = computed(() => Math.max(0, shelf.value.length - GAMES_LIMIT.value));
</script>

<template>
    <!-- The catalogue: a role and a type filter where there is a choice, one sort, one shelf. -->
    <section v-if="credits.length">
        <SectionHeading :title="shelfLabel(currentCategory)">
            <div class="flex flex-wrap items-center gap-3">
                <select
                    v-if="roles.length > 1"
                    :aria-label="trans('app.filter_by_role')"
                    :value="currentRole ?? ''"
                    :class="SELECT_CLASS"
                    @change="selectedRole = ($event.target as HTMLSelectElement).value as CompanyRole"
                >
                    <option v-for="role in roles" :key="role" :value="role">{{ ROLE_LABELS[role]() }}</option>
                </select>
                <select
                    v-if="categories.length > 1"
                    :aria-label="trans('app.filter_by_type')"
                    :value="currentCategory"
                    :class="SELECT_CLASS"
                    @change="selectedCategory = ($event.target as HTMLSelectElement).value as CatalogueShelf"
                >
                    <option v-for="category in categories" :key="category" :value="category">{{ shelfLabel(category) }}</option>
                </select>
                <SortControl v-model:sort="gameSort" v-model:dir="gameDir" :options="gameSortOptions" />
            </div>
        </SectionHeading>

        <MediaGrid>
            <MediaCard v-for="media in visibleShelf" :key="media.ref" :media="media" />
        </MediaGrid>
        <!-- One control: reveals what the page holds, then asks the provider for more. -->
        <ShowMoreToggle
            v-if="hidden > 0 || hasMoreCredits || expanded"
            v-model="expanded"
            :hidden-count="hidden"
            :can-load-more="hasMoreCredits"
            :loading="loadingMore"
            @more="loadMore"
        />
    </section>
</template>
