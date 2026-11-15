<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import SortControl from '@/components/SortControl.vue';
import { shownState, stateKey } from '@/composables/useMediaActions';
import { useShelfLimit } from '@/composables/useShelfLimit';
import { departmentLabel, type Department } from '@/lib/departments';
import { withCount } from '@/lib/format';
import { backlogOf } from '@/lib/lists';
import { inPersonPageOrder, kindPlural } from '@/lib/mediaTypes';
import { byRating, byRelease, byTitle } from '@/lib/titleSort';
import { SELECT_CLASS } from '@/lib/ui';
import type { SharedData } from '@/types';
import type { PersonCredit, PersonDetails } from '@/types/media';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

// A person's work: their titles already on the viewer's backlog and in their
// history, then every credit by department.
const props = defineProps<{ person: PersonDetails }>();

const page = usePage<SharedData>();

// Department filter, defaulting to what the person is known for.
const departments = computed(() => [...new Set(props.person.credits.map((c) => c.department))]);
const selectedDepartment = ref<Department | null>(null);
const currentDepartment = computed(
    () =>
        selectedDepartment.value ??
        (props.person.knownForDepartment && departments.value.includes(props.person.knownForDepartment)
            ? props.person.knownForDepartment
            : (departments.value[0] ?? null)),
);

// Credits of the active department and one type, deduplicated per title,
// popularity order.
function creditsOf(type: string): PersonCredit[] {
    const seen = new Set<string>();

    return props.person.credits.filter((credit) => {
        if (credit.department !== currentDepartment.value || credit.media.type !== type) {
            return false;
        }

        if (seen.has(credit.media.ref)) {
            return false;
        }

        seen.add(credit.media.ref);

        return true;
    });
}

// Credits sorting: popularity (the provider default), release date, title
// or community rating, each with a direction toggle.
type CreditSort = 'popularity' | 'release' | 'title' | 'rating';

const creditSort = ref<CreditSort>('popularity');
const creditDir = ref<'asc' | 'desc'>('desc');

watch(creditSort, () => (creditDir.value = creditSort.value === 'title' ? 'asc' : 'desc'));

const creditSortOptions = computed(() => [
    { value: 'popularity', label: trans('common.popularity') },
    { value: 'release', label: trans('common.release_date') },
    { value: 'title', label: trans('common.title') },
    { value: 'rating', label: trans('common.community_rating') },
]);

function sortCredits(credits: PersonCredit[]): PersonCredit[] {
    const dir = creditDir.value === 'asc' ? 1 : -1;

    return [...credits].sort((a, b) => {
        switch (creditSort.value) {
            case 'release':
                return dir * byRelease(a.media, b.media);
            case 'title':
                return dir * byTitle(a.media, b.media);
            case 'rating':
                return dir * byRating(a.media, b.media);
            default:
                return dir * (a.popularity - b.popularity);
        }
    });
}

// The credits open on ~10 cards, rounded up to fill the last row so the fold
// is a straight edge at every breakpoint; the rest sits behind a toggle that
// folds again when the department or the type changes.
const CREDITS_LIMIT = useShelfLimit(10);
const expanded = ref(false);

// The person page is media-agnostic: an author has books, a director films
// and shows. The type select offers the types present in the active
// department, films before shows.
const creditTypes = computed(() =>
    inPersonPageOrder(new Set(props.person.credits.filter((c) => c.department === currentDepartment.value).map((c) => c.media.type))),
);

// One type at a time, films first, as a company's page shows its titles: the
// choice holds while the department has that type, else the first type does.
const selectedType = ref<string | null>(null);
const currentType = computed(() =>
    selectedType.value !== null && creditTypes.value.includes(selectedType.value) ? selectedType.value : (creditTypes.value[0] ?? null),
);

watch([currentDepartment, currentType], () => (expanded.value = false));

// Sorted once per change rather than on every read.
const shownCredits = computed(() => sortCredits(currentType.value === null ? [] : creditsOf(currentType.value)));
const visibleCredits = computed(() => (expanded.value ? shownCredits.value : shownCredits.value.slice(0, CREDITS_LIMIT.value)));

// ---- Library connections: the person's filmography crossed with
// the viewer's own state, from the library store. ----

/**
 * All credits deduplicated per title (across departments). The subtitle shows
 * a single role — the first-seen credit's, i.e. the character when the person
 * acted — matching the cards in the Credits section below.
 */
const uniqueCredits = computed<{ credit: PersonCredit; roles: string }[]>(() => {
    const byKey = new Map<string, { credit: PersonCredit; roles: string }>();

    for (const credit of props.person.credits) {
        const key = `${credit.media.type}-${credit.media.ref}`;

        if (!byKey.has(key)) {
            byKey.set(key, { credit, roles: credit.role ?? departmentLabel(credit.department) });
        }
    }

    return [...byKey.values()];
});

const watchlistId = computed(() => {
    return backlogOf(page.props.userLists)?.id ?? null;
});

// The two library shelves fold like the credits below: ~10 cards rounded up
// to a whole row, the rest behind a toggle.
const LIBRARY_LIMIT = useShelfLimit(10);
const watchlistExpanded = ref(false);
const historyExpanded = ref(false);

/** Watchlisted titles featuring this person, with their role. */
const watchlistCredits = computed(() => {
    const id = watchlistId.value;

    return id === null ? [] : uniqueCredits.value.filter(({ credit }) => shownState(stateKey(credit.media)).listIds.includes(id));
});

/** Titles featuring this person the viewer has already played (any play counts). */
const historyCredits = computed(() =>
    uniqueCredits.value.filter(({ credit }) => {
        const state = shownState(stateKey(credit.media));

        return state.plays > 0 || state.watched;
    }),
);

const visibleWatchlistCredits = computed(() =>
    watchlistExpanded.value ? watchlistCredits.value : watchlistCredits.value.slice(0, LIBRARY_LIMIT.value),
);

const visibleHistoryCredits = computed(() => (historyExpanded.value ? historyCredits.value : historyCredits.value.slice(0, LIBRARY_LIMIT.value)));
</script>

<template>
    <div v-if="watchlistCredits.length || historyCredits.length || person.credits.length" class="space-y-12">
        <!-- Library connections: watchlist first (planning), then history -->
        <section v-if="watchlistCredits.length">
            <SectionHeading
                :title="trans('media.from_your_backlog')"
                :subtitle="trans('media.titles_on_your_backlog_connected_to', { name: person.name })"
            />
            <MediaGrid>
                <MediaCard
                    v-for="{ credit, roles } in visibleWatchlistCredits"
                    :key="`watchlist-${credit.media.type}-${credit.media.ref}`"
                    :media="credit.media"
                    :subtitle="roles"
                />
            </MediaGrid>
            <ShowMoreToggle
                v-if="watchlistCredits.length > LIBRARY_LIMIT"
                v-model="watchlistExpanded"
                :hidden-count="watchlistCredits.length - LIBRARY_LIMIT"
            />
        </section>

        <section v-if="historyCredits.length">
            <SectionHeading :title="trans('media.from_your_history')" />
            <MediaGrid>
                <MediaCard
                    v-for="{ credit, roles } in visibleHistoryCredits"
                    :key="`history-${credit.media.type}-${credit.media.ref}`"
                    :media="credit.media"
                    :subtitle="roles"
                />
            </MediaGrid>
            <ShowMoreToggle
                v-if="historyCredits.length > LIBRARY_LIMIT"
                v-model="historyExpanded"
                :hidden-count="historyCredits.length - LIBRARY_LIMIT"
            />
        </section>

        <!-- CREDITS with department filter -->
        <section v-if="person.credits.length">
            <SectionHeading :title="trans('media.credits')">
                <div class="flex flex-wrap items-center gap-3">
                    <select
                        :value="currentDepartment ?? ''"
                        :aria-label="trans('media.credits')"
                        :class="SELECT_CLASS"
                        @change="selectedDepartment = ($event.target as HTMLSelectElement).value as Department"
                    >
                        <option v-for="department in departments" :key="department" :value="department">{{ departmentLabel(department) }}</option>
                    </select>
                    <select
                        v-if="creditTypes.length > 1"
                        :aria-label="trans('app.filter_by_type')"
                        :value="currentType ?? ''"
                        :class="SELECT_CLASS"
                        @change="selectedType = ($event.target as HTMLSelectElement).value"
                    >
                        <option v-for="type in creditTypes" :key="type" :value="type">
                            {{ withCount(kindPlural(type), creditsOf(type).length) }}
                        </option>
                    </select>
                    <SortControl v-model:sort="creditSort" v-model:dir="creditDir" :options="creditSortOptions" />
                </div>
            </SectionHeading>

            <MediaGrid>
                <MediaCard
                    v-for="credit in visibleCredits"
                    :key="`${credit.media.type}-${credit.media.ref}`"
                    :media="credit.media"
                    :subtitle="credit.role"
                />
            </MediaGrid>
            <ShowMoreToggle v-if="shownCredits.length > CREDITS_LIMIT" v-model="expanded" :hidden-count="shownCredits.length - CREDITS_LIMIT" />
        </section>
    </div>
</template>
