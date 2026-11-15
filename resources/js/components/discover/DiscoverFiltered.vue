<script setup lang="ts">
import DiscoverFilters from '@/components/discover/DiscoverFilters.vue';
import DiscoverSections from '@/components/discover/DiscoverSections.vue';
import DiscoverTypeSelect from '@/components/discover/DiscoverTypeSelect.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import FiltersButton from '@/components/FiltersButton.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaCardSkeleton from '@/components/media/MediaCardSkeleton.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import SortControl from '@/components/SortControl.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { serializeDiscoverFilters } from '@/lib/discover';
import { awaitedLabel } from '@/lib/releases';
import type { DiscoverActiveFilters, DiscoverFilter, DiscoverRange, MediaSummary } from '@/types/media';
import type { PageInfo } from '@/types/pagination';
import { router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

/**
 * Discover for a provider with filtered discovery: the filters in a sidebar
 * (a dialog below `lg`), sorting and the count in the strip, and the results
 * beside them. With no filter set, the browse sections stand in for results.
 */
const props = defineProps<{
    type: string;
    availableTypes: string[];
    trending: MediaSummary[];
    popular: MediaSummary[];
    upcoming: MediaSummary[];
    filters: DiscoverFilter[];
    activeFilters: DiscoverActiveFilters;
    filterCount: number | null;
    filteredItems: MediaSummary[];
    filteredPagination: PageInfo | null;
    failed: boolean;
}>();

type FilterValue = string | string[] | DiscoverRange;

/**
 * Everything that differs between the two modes of a filtered page. `filters`
 * is here because a definition can change between requests (a dictionary goes
 * down), `failed` so the banner both appears and disappears, and `userStates`
 * because it is a plain closure prop — left out, the cards come back claiming
 * nothing is watched.
 */
const PARTIAL = ['filters', 'filteredItems', 'filteredPagination', 'activeFilters', 'filterCount', 'failed', 'userStates'];

const sortFilter = computed(() => props.filters.find((filter) => filter.key === 'sort') ?? null);

/**
 * The controls read from here, never from the props: two quick clicks must
 * both land, and the second one is built before the first has answered.
 */
const pending = ref<DiscoverActiveFilters>({ ...props.activeFilters });

const loading = ref(false);

let inFlight = 0;
let cancelPrevious: (() => void) | null = null;

// A visit that lands (including one from the back button) re-seeds the
// controls — but only while nothing is in the air, or an answer to an older
// click would undo a newer one.
watch(
    () => props.activeFilters,
    (next) => {
        if (inFlight === 0) {
            pending.value = { ...next };
        }
    },
);

const resultsMode = computed(() => Object.keys(props.activeFilters).length > 0);

const browseIsEmpty = computed(() => props.trending.length === 0 && props.popular.length === 0 && props.upcoming.length === 0);

const countLabel = computed(() =>
    resultsMode.value && props.filteredPagination ? transChoice('common.count_item', props.filteredPagination.total) : null,
);

function visit(state: DiscoverActiveFilters, page?: number): void {
    const f = serializeDiscoverFilters(state);
    const empty = Object.keys(f).length === 0;

    // An older request in the air can only answer with a staler page.
    cancelPrevious?.();

    router.get(route('discover'), empty ? { type: props.type } : { type: props.type, f, ...(page === undefined ? {} : { page }) }, {
        preserveState: true,
        preserveScroll: page === undefined,
        // Emptying the set brings the browse sections back, and those ride
        // only on a full response.
        only: empty ? [] : PARTIAL,
        onCancelToken: (token) => (cancelPrevious = token.cancel),
        onStart: () => {
            inFlight += 1;
            loading.value = true;
        },
        onFinish: () => {
            inFlight = Math.max(0, inFlight - 1);
            loading.value = inFlight > 0;
            cancelPrevious = null;
        },
    });
}

function onFilterChange(key: string, value: FilterValue | undefined): void {
    const next = { ...pending.value };

    if (value === undefined) {
        delete next[key];
    } else {
        next[key] = value;
    }

    pending.value = next;
    visit(next);
}

/**
 * Sorting arrives as one value per direction (`first_air_date.desc` and its
 * `.asc` twin, in whatever spelling the provider uses). The control shows one
 * axis and a direction, so the two are split on the way in and joined back on
 * the way out — the axis is the option's own value, the descending run.
 */
const sortOptions = computed(() =>
    (sortFilter.value?.options ?? []).map((option) => ({
        value: option.value,
        label: option.label,
        directional: option.ascending != null,
    })),
);

const currentSort = computed(() => (pending.value.sort as string | undefined) ?? sortFilter.value?.default ?? '');

const currentSortOption = computed(
    () => (sortFilter.value?.options ?? []).find((option) => option.value === currentSort.value || option.ascending === currentSort.value) ?? null,
);

function applySort(value: string): void {
    // The resting order is not a filter: choosing it by hand and never touching
    // the control lead to the same address.
    onFilterChange('sort', value === '' || value === sortFilter.value?.default ? undefined : value);
}

const sortAxis = computed({
    get: () => currentSortOption.value?.value ?? '',
    // A fresh axis starts at its primary run: newest before oldest.
    set: (value: string) => applySort(value),
});

const sortDir = computed<'asc' | 'desc'>({
    get: () => (currentSortOption.value?.ascending === currentSort.value ? 'asc' : 'desc'),
    set: (dir) => {
        const option = currentSortOption.value;

        if (option) {
            applySort(dir === 'asc' && option.ascending != null ? option.ascending : option.value);
        }
    },
});

function clearFilters(): void {
    pending.value = {};
    visit({});
}

function gotoPage(page: number): void {
    visit(pending.value, page);
}

/* ------------------------------- Narrow screen --------------------------- */

// On a narrow screen the results are not next to the controls, so applying
// every tick would reload a page the user cannot see. The dialog keeps its own
// draft and commits it once; closing it walks away from the
// draft, which also means the two-clicks race cannot happen here.
const dialogOpen = ref(false);
const draft = ref<DiscoverActiveFilters>({});

function onDialogToggle(open: boolean): void {
    if (open) {
        draft.value = { ...pending.value };
    }

    dialogOpen.value = open;
}

function onDraftChange(key: string, value: FilterValue | undefined): void {
    const next = { ...draft.value };

    if (value === undefined) {
        delete next[key];
    } else {
        next[key] = value;
    }

    draft.value = next;
}

function applyDraft(): void {
    const next = draft.value;

    pending.value = next;
    dialogOpen.value = false;
    visit(next);
}
</script>

<template>
    <!-- With filters in the sidebar the strip carries sorting and the count,
         so it never grows a competing filter panel of its own. -->
    <PageToolbar variant="band" :count="countLabel" :filter-count="null">
        <template #search>
            <!-- Below `md` the strip carries filters and sorting alone. -->
            <DiscoverTypeSelect :type="type" :available-types="availableTypes" />

            <!-- Below `lg` the sidebar is gone, so the same set opens in a
                 dialog. The badge says what the sidebar would show. -->
            <FiltersButton
                v-if="filters.length > 0"
                :count="filterCount ?? 0"
                :active="(filterCount ?? 0) > 0"
                :expanded="dialogOpen"
                variant="band"
                class="lg:hidden"
                @click="onDialogToggle(true)"
            />
        </template>

        <template v-if="sortFilter && !sortFilter.unavailable" #sort>
            <SortControl v-model:sort="sortAxis" v-model:dir="sortDir" :options="sortOptions" />
        </template>
    </PageToolbar>

    <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
        <ErrorBanner v-if="failed" :message="trans('discover.could_not_load_some_sections_right')" />

        <div class="lg:grid lg:grid-cols-[16rem_1fr] lg:items-start lg:gap-8">
            <aside class="hidden lg:block">
                <DiscoverFilters v-if="filters.length > 0" :filters="filters" :model-value="pending" @update="onFilterChange" @clear="clearFilters" />
                <p v-else class="text-ink-faint m-0 text-base">{{ trans('discover.temporarily_unavailable') }}</p>
            </aside>

            <div>
                <template v-if="resultsMode">
                    <MediaGrid v-if="loading" :columns="4">
                        <MediaCardSkeleton v-for="n in 20" :key="n" />
                    </MediaGrid>
                    <MediaGrid v-else-if="filteredItems.length > 0" :columns="4">
                        <MediaCard
                            v-for="media in filteredItems"
                            :key="`filtered-${media.type}-${media.ref}`"
                            :media="media"
                            :subtitle="awaitedLabel(media)"
                        />
                    </MediaGrid>
                    <EmptyState v-else-if="!failed" :message="trans('discover.nothing_to_show_right_now_please')" />

                    <div v-if="filteredPagination && filteredPagination.totalPages > 1" class="mt-10">
                        <Pagination :current-page="filteredPagination.currentPage" :total-pages="filteredPagination.totalPages" @goto="gotoPage" />
                    </div>
                </template>

                <template v-else>
                    <EmptyState v-if="browseIsEmpty && !failed" :message="trans('discover.nothing_to_show_right_now_please')" />

                    <DiscoverSections :type="type" :trending="trending" :popular="popular" :upcoming="upcoming" :columns="4" />
                </template>
            </div>
        </div>

        <!-- The same filters below `lg`, collected as a draft and applied in
             one go — a tick that reloads results the user cannot see is worse
             than no live update. Kept out of the grid above: it is a portal,
             not a column. -->
        <Dialog v-if="filters.length > 0" :open="dialogOpen" @update:open="onDialogToggle">
            <DialogScrollContent>
                <DialogHeader>
                    <DialogTitle>{{ trans('common.filters') }}</DialogTitle>
                </DialogHeader>

                <DiscoverFilters :filters="filters" :model-value="draft" @update="onDraftChange" @clear="draft = {}" />

                <DialogFooter>
                    <Button type="button" class="w-full" @click="applyDraft">
                        {{ trans('discover.show_results') }}
                    </Button>
                </DialogFooter>
            </DialogScrollContent>
        </Dialog>
    </div>
</template>
