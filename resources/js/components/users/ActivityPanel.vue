<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import SearchInput from '@/components/SearchInput.vue';
import SortControl from '@/components/SortControl.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import { leafCode, showTitleOf } from '@/lib/badges';
import { formatDateTime, formatDayKey, formatNumber, formatRuntime } from '@/lib/format';
import { unitGlyph } from '@/lib/progress';
import { SELECT_CLASS } from '@/lib/ui';
import type { ActivityEntry, ActivityFilters } from '@/types/media';
import type { PageInfo } from '@/types/pagination';
import { router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { CalendarDays, Clock, LayoutGrid, Trash2, type LucideIcon } from '@lucide/vue';
import { computed } from 'vue';

// What a day of a log panel cost, by unit. Time and reading counters never add
// up, and playtime stays apart from watch time — one finished 400-hour game
// would otherwise swallow the day. Icons match the dashboard stat tiles.
const DAY_UNITS = ['watch', 'minutes', 'pages', 'chapters', 'issues'] as const;

type DayUnit = (typeof DAY_UNITS)[number];

const unitIcon = (unit: DayUnit): LucideIcon => (unit === 'watch' ? Clock : (unitGlyph(unit) ?? Clock));

const props = withDefaults(
    defineProps<{
        /** Route of the panel this is — every control change is a visit to it. */
        routeName: string;
        username: string;
        isOwner: boolean;
        entries: ActivityEntry[];
        pagination: PageInfo | null;
        filters: ActivityFilters;
        genreOptions: string[];
        typeOptions: string[];
        /**
         * Label of the "activity" sort — each panel dates its entries its own
         * way. `null` where the panel has no date of its own beyond when the
         * row was added (favourites), so the axis would duplicate "Date added".
         */
        sortLabel?: string | null;
        /** What each day cost, per unit, keyed by day (YYYY-MM-DD). */
        dayTotals?: Record<string, Partial<Record<DayUnit, number>>>;
        /** Panels with an outcome (history) offer the completed / dropped filter. */
        withStatus?: boolean;
        withHideRated?: boolean;
        /** Whether to repeat the current/total page count below the numbered pagination. */
        showPaginationSummary?: boolean;
        /** Owner-only removal; null when the panel has nothing to remove. */
        removeLabel?: string | null;
    }>(),
    {
        sortLabel: null,
        dayTotals: () => ({}),
        withStatus: false,
        withHideRated: true,
        showPaginationSummary: true,
        removeLabel: null,
    },
);

const emit = defineEmits<{ remove: [entry: ActivityEntry] }>();

// ---- The three mechanisms (sort / group / filter) live in GET params;
// every control change is a fresh visit, so back/refresh keeps the spot. ----

function visit(overrides: Record<string, unknown> = {}): void {
    const f = props.filters;
    const params: Record<string, unknown> = {
        group: f.group,
        sort: f.sort,
        dir: f.dir,
        q: f.q ?? undefined,
        genre: f.genre ?? undefined,
        type: f.type ?? undefined,
        status: f.status ?? undefined,
        from: f.from ?? undefined,
        to: f.to ?? undefined,
        hide_rated: f.hideRated ? 1 : undefined,
        hide_listed: f.hideListed ? 1 : undefined,
        page: undefined,
        ...overrides,
    };

    for (const key of Object.keys(params)) {
        if (params[key] === undefined || params[key] === null || params[key] === '') {
            delete params[key];
        }
    }

    router.get(route(props.routeName, { user: props.username }), params as Record<string, string>, {
        preserveState: true,
        preserveScroll: false,
    });
}

// SearchInput debounces for us; preserveState keeps the box focused across
// the visit it fires.
const searchQuery = computed<string | null>({ get: () => props.filters.q, set: (q) => visit({ q: q ?? undefined }) });

const sortOptions = computed(() => [
    ...(props.sortLabel === null ? [] : [{ value: 'activity', label: props.sortLabel }]),
    { value: 'added', label: trans('common.date_added') },
    { value: 'my_rating', label: trans('common.my_rating') },
    { value: 'community', label: trans('common.community_rating') },
    { value: 'title', label: trans('common.title') },
    { value: 'release', label: trans('common.release_date') },
    { value: 'runtime', label: trans('common.runtime') },
]);

const typeFilterOptions = computed(() => props.typeOptions.map((type) => ({ type })));

const typeFilter = computed<string | null>({ get: () => props.filters.type, set: (type) => visit({ type: type ?? undefined }) });

const sortValue = computed<string>({ get: () => props.filters.sort, set: (sort) => visit({ sort }) });

const sortDir = computed<'asc' | 'desc'>({ get: () => props.filters.dir, set: (dir) => visit({ dir }) });

const activeFilterCount = computed(() => {
    const f = props.filters;

    return [f.genre, f.type, f.status, f.from, f.to].filter((value) => value !== null).length + [f.hideRated, f.hideListed].filter(Boolean).length;
});

function clearFilters(): void {
    visit({
        genre: undefined,
        type: undefined,
        status: undefined,
        from: undefined,
        to: undefined,
        hide_rated: undefined,
        hide_listed: undefined,
    });
}

// ---- Day groups (server-computed keys, so grouping matches pagination) ----

const dayGroups = computed(() => {
    const groups = new Map<string, ActivityEntry[]>();

    for (const entry of props.entries) {
        const day = entry.day ?? '';
        groups.set(day, [...(groups.get(day) ?? []), entry]);
    }

    return [...groups.entries()].map(([day, dayEntries]) => ({ day, entries: dayEntries }));
});

// Day heading segments. The counters read themselves ("285 pages"); the two
// time segments need a label to tell watching from playing.
const daySegments = computed(() => {
    const segments: Record<string, { unit: DayUnit; icon: LucideIcon; label: string | null; text: string }[]> = {};

    for (const [day, totals] of Object.entries(props.dayTotals)) {
        segments[day] = DAY_UNITS.filter((unit) => (totals[unit] ?? 0) > 0).map((unit) => ({
            unit,
            icon: unitIcon(unit),
            label: unit === 'watch' ? trans('common.time_watched') : unit === 'minutes' ? trans('users.playtime') : null,
            text: unitTotal(unit, totals[unit] ?? 0),
        }));
    }

    return segments;
});

function unitTotal(unit: DayUnit, amount: number): string {
    const count = { count: formatNumber(amount) };

    switch (unit) {
        case 'pages':
            return transChoice('common.count_page', amount, count);
        case 'chapters':
            return transChoice('common.count_chapter', amount, count);
        case 'issues':
            return transChoice('common.count_issue', amount, count);
        default:
            return formatRuntime(amount) ?? '';
    }
}

// ---- Card context ----

function subtitle(entry: ActivityEntry): string | null {
    return showTitleOf(entry.media);
}
</script>

<template>
    <!-- Toolbar: search + sorting + filters on the left, grouping on the
         right. It is the shared band, so it runs the full width and brings its
         own container — the panel's own results open in the one below it. -->
    <PageToolbar
        variant="band"
        :count="pagination ? transChoice('common.count_item', pagination.total) : null"
        :filter-count="activeFilterCount"
        @clear-filters="clearFilters"
    >
        <template #search>
            <SearchInput v-model="searchQuery" />
        </template>

        <template #filters>
            <TypeFilter v-model="typeFilter" :options="typeFilterOptions" :labelled="true" />

            <label v-if="genreOptions.length > 0" class="flex flex-col gap-1.5">
                <span class="text-ink-muted text-base font-bold">{{ trans('common.genre') }}</span>
                <select
                    data-toolbar-control
                    :value="filters.genre ?? ''"
                    :class="SELECT_CLASS"
                    @change="visit({ genre: ($event.target as HTMLSelectElement).value })"
                >
                    <option value="">{{ trans('common.all') }}</option>
                    <option v-for="genre in genreOptions" :key="genre" :value="genre">{{ genre }}</option>
                </select>
            </label>

            <!-- Completed vs dropped attempts; in-progress is its own panel. -->
            <label v-if="withStatus" class="flex flex-col gap-1.5">
                <span class="text-ink-muted text-base font-bold">{{ trans('common.status') }}</span>
                <select
                    data-toolbar-control
                    :value="filters.status ?? ''"
                    :class="SELECT_CLASS"
                    @change="visit({ status: ($event.target as HTMLSelectElement).value })"
                >
                    <option value="">{{ trans('common.all') }}</option>
                    <option value="completed">{{ trans('common.completed') }}</option>
                    <option value="dropped">{{ trans('common.dropped') }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="text-ink-muted text-base font-bold">{{ trans('common.from') }}</span>
                <input
                    data-toolbar-control
                    type="date"
                    :value="filters.from ?? ''"
                    :class="SELECT_CLASS"
                    @change="visit({ from: ($event.target as HTMLInputElement).value })"
                />
            </label>
            <label class="flex flex-col gap-1.5">
                <span class="text-ink-muted text-base font-bold">{{ trans('common.to') }}</span>
                <input
                    data-toolbar-control
                    type="date"
                    :value="filters.to ?? ''"
                    :class="SELECT_CLASS"
                    @change="visit({ to: ($event.target as HTMLInputElement).value })"
                />
            </label>

            <div class="flex flex-wrap items-center gap-4 pb-1">
                <label v-if="withHideRated" class="text-ink-secondary flex cursor-pointer items-center gap-2 text-base font-semibold">
                    <input
                        type="checkbox"
                        class="accent-brand size-4"
                        :checked="filters.hideRated"
                        @change="visit({ hide_rated: filters.hideRated ? undefined : 1 })"
                    />
                    {{ trans('common.hide_rated') }}
                </label>
                <label class="text-ink-secondary flex cursor-pointer items-center gap-2 text-base font-semibold">
                    <input
                        type="checkbox"
                        class="accent-brand size-4"
                        :checked="filters.hideListed"
                        @change="visit({ hide_listed: filters.hideListed ? undefined : 1 })"
                    />
                    {{ trans('users.hide_listed') }}
                </label>
            </div>
        </template>

        <template #sort>
            <SortControl v-if="filters.group === 'grid'" v-model:sort="sortValue" v-model:dir="sortDir" :options="sortOptions" />
        </template>

        <template #view>
            <div data-toolbar-control class="border-line bg-surface flex h-10 items-center rounded-lg border p-1">
                <button
                    type="button"
                    class="flex h-full items-center gap-2 rounded-md px-3 text-base font-semibold transition-colors duration-150"
                    :class="filters.group === 'day' ? 'bg-brand text-on-accent' : 'text-ink-muted hover:text-ink'"
                    :aria-pressed="filters.group === 'day'"
                    @click="filters.group !== 'day' && visit({ group: 'day' })"
                >
                    <CalendarDays class="size-4" />
                    {{ trans('users.grouped_by_day') }}
                </button>
                <button
                    type="button"
                    class="flex h-full items-center gap-2 rounded-md px-3 text-base font-semibold transition-colors duration-150"
                    :class="filters.group === 'grid' ? 'bg-brand text-on-accent' : 'text-ink-muted hover:text-ink'"
                    :aria-pressed="filters.group === 'grid'"
                    @click="filters.group !== 'grid' && visit({ group: 'grid' })"
                >
                    <LayoutGrid class="size-4" />
                    {{ trans('users.grid') }}
                </button>
            </div>
        </template>
    </PageToolbar>

    <!-- The results open in the page's own container, below the band. -->
    <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
        <!-- Empty state -->
        <EmptyState v-if="entries.length === 0" :message="trans('common.nothing_here_yet')" />

        <template v-else>
            <div v-for="group in filters.group === 'day' ? dayGroups : [{ day: '', entries }]" :key="group.day" class="mb-10">
                <!-- The rule runs under the whole heading, setting the date and
                     the day's totals apart from their cards. -->
                <div
                    v-if="group.day"
                    class="border-line-subtle/40 mb-5 flex flex-col items-start gap-x-4 gap-y-2 border-b pb-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <h3 class="text-ink text-lg font-semibold">{{ formatDayKey(group.day) }}</h3>
                    <div class="text-ink-muted flex w-full flex-wrap items-center gap-x-4 gap-y-1 text-sm font-semibold sm:w-auto">
                        <span
                            v-for="segment in daySegments[group.day] ?? []"
                            :key="segment.unit"
                            v-tooltip="segment.label"
                            class="flex items-center gap-1.5"
                        >
                            <component :is="segment.icon" class="size-4" aria-hidden="true" />
                            <span v-if="segment.label" class="sr-only">{{ segment.label }}:</span>
                            {{ segment.text }}
                        </span>
                    </div>
                </div>
                <MediaGrid>
                    <div v-for="entry in group.entries" :key="entry.id ?? `${entry.media.type}-${entry.media.ref}`" class="flex flex-col">
                        <MediaCard :media="entry.media" :subtitle="subtitle(entry)" :corner-badge="leafCode(entry.media)" />
                        <div class="text-ink-faint mt-2 flex items-center justify-center gap-2 text-sm font-semibold">
                            <span>{{ formatDateTime(entry.at) }}</span>
                            <!-- The page asks before the entry goes. -->
                            <button
                                v-if="isOwner && removeLabel !== null && entry.id !== null"
                                v-tooltip="removeLabel"
                                :aria-label="removeLabel"
                                type="button"
                                class="text-ink-faint hover:text-danger flex items-center gap-1 rounded-md px-1.5 py-0.5 transition-colors duration-150"
                                @click="emit('remove', entry)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                    </div>
                </MediaGrid>
            </div>
        </template>

        <!-- Pagination: visible page numbers and totals -->
        <div v-if="pagination && pagination.totalPages > 1" class="mt-6 flex flex-col items-center gap-2">
            <Pagination :current-page="pagination.currentPage" :total-pages="pagination.totalPages" @goto="(page) => visit({ page })" />
            <span v-if="showPaginationSummary" class="text-ink-faint text-sm">
                {{ trans('common.page_current_of_total', { current: String(pagination.currentPage), total: String(pagination.totalPages) }) }}
            </span>
        </div>
    </div>
</template>
