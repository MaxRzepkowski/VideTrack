<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import SearchInput from '@/components/SearchInput.vue';
import SortControl from '@/components/SortControl.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import UpNextCard from '@/components/dashboard/UpNextCard.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import ProgressCaption from '@/components/media/ProgressCaption.vue';
import ProgressHint from '@/components/media/ProgressHint.vue';
import UserHeader from '@/components/users/UserHeader.vue';
import { useReloadAfterWrites } from '@/composables/useReloadAfterWrites';
import AppLayout from '@/layouts/AppLayout.vue';
import { episodeCode } from '@/lib/format';
import { activityProgress } from '@/lib/progress';
import { SELECT_CLASS } from '@/lib/ui';
import type { MediaUserStatus, ProgressSort, SortDirection, TypeCount } from '@/types/generated';
import type { ProgressEntry, ProfileData } from '@/types/media';
import { Head, router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    profile: ProfileData;
    isOwner: boolean;
    entries: ProgressEntry[];
    types: TypeCount[];
    filters: { type: string | null; status: MediaUserStatus | null; q: string | null; sort: ProgressSort; dir: SortDirection };
}>();

// The two shelves, typed by the server's statuses.
const SHELVES: { value: MediaUserStatus; label: () => string }[] = [
    { value: 'in_progress', label: () => trans('common.in_progress') },
    { value: 'on_hold', label: () => trans('common.on_hold') },
];

/** The shelf a select value names; "All" (empty) names none. */
function shelfOf(value: string): MediaUserStatus | null {
    return SHELVES.find((shelf) => shelf.value === value)?.value ?? null;
}

// A title leaves or moves with its progress.
useReloadAfterWrites(['entries', 'types', 'userStates']);

// ---- Controls: every one of them lives in the query string, so a shelf a
// user narrowed down is a link they can keep. ----

function visit(overrides: Partial<typeof props.filters> = {}): void {
    const merged = { ...props.filters, ...overrides };
    const params: Record<string, string> = {};

    if (merged.type) params.type = merged.type;
    if (merged.status) params.status = merged.status;
    if (merged.q) params.q = merged.q;
    if (merged.sort !== 'activity') params.sort = merged.sort;
    if (merged.dir !== 'asc') params.dir = merged.dir;

    router.get(route('users.progress', { user: props.profile.username }), params, { preserveState: true, preserveScroll: false });
}

const searchQuery = computed<string | null>({ get: () => props.filters.q, set: (q) => visit({ q }) });
const typeFilter = computed<string | null>({ get: () => props.filters.type, set: (type) => visit({ type }) });
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

// "Left to finish" is the share of the title still ahead — the one axis a
// show, a book and a game can share.
const sortOptions = computed<{ value: ProgressSort; label: string }[]>(() => [
    { value: 'activity', label: trans('common.last_activity') },
    { value: 'remaining', label: trans('common.left_to_finish') },
    { value: 'title', label: trans('common.title') },
]);

const activeFilterCount = computed(() => [props.filters.type, props.filters.status].filter((value) => value !== null).length);

// The active shelf first, then everything on hold; headings only once the
// second shelf exists.
const shelves = computed(() => {
    const cards = props.entries.map((entry) => ({ entry, progress: activityProgress(entry) }));
    const active = cards.filter((card) => card.entry.section !== 'on_hold');
    const held = cards.filter((card) => card.entry.section === 'on_hold');

    return [
        { key: 'in_progress', label: held.length > 0 ? trans('common.in_progress') : null, cards: active },
        { key: 'on_hold', label: trans('common.on_hold'), cards: held },
    ].filter((shelf) => shelf.cards.length > 0);
});

function nextEpisodeLabel(entry: ProgressEntry): string[] {
    const next = entry.nextEpisode;

    return next ? [episodeCode(next.seasonNumber ?? 0, next.number ?? 0), next.title] : [];
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('users.name_in_progress', { name: profile.name })" />

        <!-- The lit tab is this page's title, so no line repeats it in words.
             What it cannot carry — a heading for a screen reader — stays, out
             of sight; the count in the strip below says the rest. -->
        <h1 class="sr-only">{{ trans('common.in_progress_2') }}</h1>

        <PageMasthead class="pb-4">
            <UserHeader :profile="profile" :is-owner="isOwner" active="progress" identity="link" />
        </PageMasthead>

        <PageToolbar
            variant="band"
            :count="transChoice('common.count_item', entries.length)"
            :filter-count="activeFilterCount"
            @clear-filters="visit({ type: null, status: null })"
        >
            <template #search>
                <SearchInput v-model="searchQuery" />
            </template>

            <template #filters>
                <TypeFilter v-model="typeFilter" :options="types" :labelled="true" />

                <!-- The two shelves, as a filter: one of them alone. -->
                <label class="flex flex-col gap-1.5">
                    <span class="text-ink-muted text-base font-bold">{{ trans('common.status') }}</span>
                    <select
                        data-toolbar-control
                        :value="filters.status ?? ''"
                        :class="SELECT_CLASS"
                        @change="visit({ status: shelfOf(($event.target as HTMLSelectElement).value) })"
                    >
                        <option value="">{{ trans('common.all') }}</option>
                        <option v-for="shelf in SHELVES" :key="shelf.value" :value="shelf.value">{{ shelf.label() }}</option>
                    </select>
                </label>
            </template>

            <template #sort>
                <SortControl v-model:sort="sortValue" v-model:dir="sortDir" :options="sortOptions" />
            </template>
        </PageToolbar>

        <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
            <EmptyState
                v-if="entries.length === 0"
                :message="filters.q ? trans('common.no_results_for_query', { query: filters.q }) : trans('common.nothing_here_yet')"
            />

            <div v-for="shelf in shelves" :key="shelf.key" class="mb-10">
                <h2 v-if="shelf.label" class="text-ink mb-4 text-lg font-semibold">{{ shelf.label }}</h2>
                <MediaGrid>
                    <!-- A show in the middle shows up as the episode it is up
                         to — the dashboard's card, whole. Everything else (a
                         game, a book, a paused film) is its own card. -->
                    <template v-for="{ entry, progress } in shelf.cards" :key="`${entry.media.type}-${entry.media.ref}`">
                        <UpNextCard v-if="entry.upNext" :entry="entry.upNext" :show-type-badge="true" />
                        <div v-else class="flex flex-col">
                            <MediaCard :media="entry.media" :subtitle="nextEpisodeLabel(entry)" />
                            <div class="mt-3">
                                <!-- A reading type has no episodes to report,
                                     so its bar stays silent (the rows come
                                     back null) — only shows get the hint. -->
                                <ProgressHint :progress="entry">
                                    <div
                                        v-if="progress?.percent != null"
                                        class="bg-line h-1.5 w-full overflow-hidden rounded-full"
                                        role="progressbar"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        :aria-valuenow="progress.percent"
                                        :aria-valuetext="progress.caption"
                                    >
                                        <div class="bg-brand h-full rounded-full" :style="{ width: `${progress.percent}%` }"></div>
                                    </div>
                                    <ProgressCaption
                                        v-if="progress"
                                        :text="progress.caption"
                                        :mobile-text="progress.mobileCaption"
                                        :remaining="progress.remaining"
                                    />
                                </ProgressHint>
                            </div>
                        </div>
                    </template>
                </MediaGrid>
            </div>
        </div>
    </AppLayout>
</template>
