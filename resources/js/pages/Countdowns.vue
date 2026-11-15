<script setup lang="ts">
import CountdownCard from '@/components/countdown/CountdownCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import EventCountdownCard from '@/components/events/EventCountdownCard.vue';
import PageHeading from '@/components/PageHeading.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import SearchInput from '@/components/SearchInput.vue';
import SortControl from '@/components/SortControl.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { EVENT_FILTER_TYPE } from '@/lib/liveEvents';
import type { CountdownSort, EventCountdown, SortDirection, TypeCount } from '@/types/generated';
import type { CountdownEntry } from '@/types/media';
import type { PageInfo } from '@/types/pagination';
import { Head, router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Timer } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    entries: (CountdownEntry | EventCountdown)[];
    types: TypeCount[];
    filters: { type: string | null; q: string | null; sort: CountdownSort; dir: SortDirection };
    pagination: PageInfo;
}>();

// Every control lives in the query string; a change is a fresh visit that
// resets scroll (the controls sit at the top).
function visit(overrides: Partial<typeof props.filters> & { page?: number } = {}): void {
    const merged = { ...props.filters, page: 1, ...overrides };
    const params: Record<string, string | number> = {};

    if (merged.type) params.type = merged.type;
    if (merged.q) params.q = merged.q;
    if (merged.sort !== 'premiere') params.sort = merged.sort;
    if (merged.dir !== 'asc') params.dir = merged.dir;
    if (merged.page > 1) params.page = merged.page;

    router.get(route('countdowns'), params, { preserveState: true, preserveScroll: false });
}

// The props are the single source; each setter navigates rather than holding
// local state that could drift from the URL.
const typeFilter = computed<string | null>({ get: () => props.filters.type, set: (type) => visit({ type }) });
const searchQuery = computed<string | null>({ get: () => props.filters.q, set: (q) => visit({ q }) });
// The control hands back a string; only an axis the page offers is sent.
const sortValue = computed<string>({
    get: () => props.filters.sort,
    set: (sort) => {
        const axis = sortOptions.value.find((option) => option.value === sort);

        if (axis) {
            visit({ sort: axis.value });
        }
    },
});
const sortDir = computed<'asc' | 'desc'>({ get: () => props.filters.dir, set: (dir) => visit({ dir }) });

// "Premiere date" ascending is soonest-first, which is what the page is for.
const sortOptions = computed<{ value: CountdownSort; label: string }[]>(() => [
    { value: 'premiere', label: trans('calendar.premiere_date') },
    { value: 'title', label: trans('common.title') },
]);

// Only the type narrows the set here, so it is the whole badge count.
const activeFilterCount = computed(() => (props.filters.type === null ? 0 : 1));

// Events are an option of their own beside the media types, under their own name.
const typeOptions = computed(() =>
    props.types.map((option) => (option.type === EVENT_FILTER_TYPE ? { ...option, label: trans('calendar.event') } : option)),
);
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.countdowns')" />

        <PageMasthead>
            <div class="max-w-content mx-auto w-full px-4 pt-8 pb-6 md:px-10">
                <PageHeading :title="trans('common.countdowns')" :subtitle="trans('calendar.track_the_time_left_until_upcoming')">
                    <template #icon><Timer class="size-6" /></template>
                </PageHeading>
            </div>
        </PageMasthead>

        <PageToolbar
            variant="band"
            :count="transChoice('common.count_item', pagination.total)"
            :filter-count="activeFilterCount"
            @clear-filters="visit({ type: null })"
        >
            <template #search>
                <SearchInput v-model="searchQuery" />
            </template>

            <template #filters>
                <TypeFilter v-model="typeFilter" :options="typeOptions" :labelled="true" />
            </template>

            <template #sort>
                <SortControl v-model:sort="sortValue" v-model:dir="sortDir" :options="sortOptions" />
            </template>
        </PageToolbar>

        <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
            <!-- Premieres and events in one soonest-first grid; a show stays,
                 marked live, until it is over. -->
            <div v-if="entries.length" class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <template
                    v-for="entry in entries"
                    :key="'event' in entry ? `event-${entry.event.provider}-${entry.event.ref}` : `${entry.media.type}-${entry.media.ref}`"
                >
                    <EventCountdownCard v-if="'event' in entry" :event="entry.event" :medium-type="entry.mediumType" />
                    <CountdownCard v-else :entry="entry" />
                </template>
            </div>

            <EmptyState v-else :message="trans('calendar.no_upcoming_premieres_to_count_down')" />

            <div v-if="pagination.totalPages > 1" class="mt-8">
                <Pagination :current-page="pagination.currentPage" :total-pages="pagination.totalPages" @goto="(page) => visit({ page })" />
            </div>
        </div>
    </AppLayout>
</template>
