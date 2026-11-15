<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import PageHeading from '@/components/PageHeading.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import SearchInput from '@/components/SearchInput.vue';
import SortControl from '@/components/SortControl.vue';
import SubjectCard from '@/components/suggestions/SubjectCard.vue';
import TextLink from '@/components/TextLink.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { withCount } from '@/lib/format';
import { SELECT_CLASS } from '@/lib/ui';
import type { ShelfSort, SortDirection, TypeCount } from '@/types/generated';
import type { PageInfo } from '@/types/pagination';
import type { OpenedSubject, Suggestion, SuggestionOrigin, SuggestionSubject } from '@/types/suggestions';
import { Head, router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * One kind of suggestion. A kind with subjects (collections) lists the
 * subjects, and a subject's page lists its titles; a kind without them lists
 * its titles straight away, with why each is here under its card.
 *
 * Unlike other pages whose props follow the library, this one does not reload
 * after a write: a title marked here keeps its place and
 * shows its new state, and leaves the shelf when the page is next opened, so
 * nothing reshuffles under the cursor.
 */
const props = defineProps<{
    shelf: { key: string; title: string; description: string };
    /** Set on a subject's own page. */
    subject: OpenedSubject | null;
    /** The subjects to pick from; null where the page lists titles. */
    subjects: SuggestionSubject[] | null;
    suggestions: Suggestion[];
    types: TypeCount[];
    /** The ways a title gets onto this shelf, where there is more than one to pick from. */
    origins: SuggestionOrigin[];
    /** The decades the shelf's titles came out in, latest first. */
    decades: { decade: number; count: number }[];
    /** The orders this page can be read in. */
    sorts: ShelfSort[];
    filters: { type: string | null; origin: string | null; decade: number | null; q: string | null; sort: ShelfSort; dir: SortDirection };
    pagination: PageInfo;
}>();

const shelfHref = computed(() => route('suggestions.show', { shelf: props.shelf.key }));
const subjectHref = (key: string): string => route('suggestions.subject', { shelf: props.shelf.key, subject: key });

// Every control lives in the query string; a change is a fresh visit.
function visit(overrides: Partial<typeof props.filters> & { page?: number } = {}): void {
    const merged = { ...props.filters, page: 1, ...overrides };
    const params: Record<string, string | number> = {};

    if (merged.type) params.type = merged.type;
    if (merged.origin) params.origin = merged.origin;
    if (merged.decade) params.decade = merged.decade;
    if (merged.q) params.q = merged.q;
    if (merged.sort !== 'suggested') params.sort = merged.sort;
    if (merged.dir !== 'asc') params.dir = merged.dir;
    if (merged.page > 1) params.page = merged.page;

    router.get(props.subject === null ? shelfHref.value : subjectHref(props.subject.key), params, { preserveState: true, preserveScroll: false });
}

const typeFilter = computed<string | null>({ get: () => props.filters.type, set: (type) => visit({ type }) });
const originFilter = computed<string | null>({ get: () => props.filters.origin, set: (origin) => visit({ origin }) });
const decadeFilter = computed<number | null>({ get: () => props.filters.decade, set: (decade) => visit({ decade }) });
const searchQuery = computed<string | null>({ get: () => props.filters.q, set: (q) => visit({ q }) });
const activeFilterCount = computed(() => [props.filters.type, props.filters.origin, props.filters.decade].filter((value) => value !== null).length);

// What an order is called depends on what the page lists: a collection's
// "release" is the release of its newest missing title.
const sortLabels = computed<Record<ShelfSort, string>>(() => ({
    suggested: trans('suggestions.sort_suggested'),
    release: props.subjects === null ? trans('common.release_date') : trans('suggestions.sort_newest_missing'),
    title: trans('common.title'),
    rating: trans('common.community_rating'),
    added: trans('common.date_added'),
    remaining: trans('suggestions.sort_left_to_watch'),
    progress: trans('common.progress'),
}));
const sortOptions = computed(() => props.sorts.map((value) => ({ value, label: sortLabels.value[value] })));
// The control hands back a string; only an order the page offers is sent.
const sortValue = computed<string>({
    get: () => props.filters.sort,
    set: (sort) => {
        const offered = props.sorts.find((value) => value === sort);

        if (offered) {
            visit({ sort: offered });
        }
    },
});
const sortDir = computed<SortDirection>({ get: () => props.filters.dir, set: (dir) => visit({ dir }) });
const isEmpty = computed(() => (props.subjects === null ? props.suggestions.length === 0 : props.subjects.length === 0));
</script>

<template>
    <AppLayout>
        <Head :title="subject?.title ?? shelf.title" />

        <PageMasthead>
            <div class="max-w-content mx-auto w-full px-4 pt-8 pb-6 md:px-10">
                <nav class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-base" :aria-label="trans('suggestions.suggestions')">
                    <TextLink :href="route('suggestions.index')">{{ trans('suggestions.suggestions') }}</TextLink>
                    <template v-if="subject !== null">
                        <span class="bg-line h-3 w-px shrink-0" aria-hidden="true"></span>
                        <TextLink :href="shelfHref">{{ shelf.title }}</TextLink>
                    </template>
                </nav>
                <PageHeading :title="subject?.title ?? shelf.title" :subtitle="subject === null ? shelf.description : subject.caption">
                    <template #icon><Sparkles class="size-6" /></template>
                </PageHeading>
                <p v-if="subject?.href" class="m-0 mt-3 text-base">
                    <TextLink :href="subject.href">{{ trans('suggestions.open_page') }}</TextLink>
                </p>
            </div>
        </PageMasthead>

        <PageToolbar
            variant="band"
            :count="transChoice('common.count_item', pagination.total)"
            :filter-count="activeFilterCount"
            @clear-filters="visit({ type: null, origin: null, decade: null })"
        >
            <template #search>
                <SearchInput v-model="searchQuery" />
            </template>

            <template #filters>
                <TypeFilter v-model="typeFilter" :options="types" :labelled="true" />

                <label v-if="origins.length > 0" class="flex flex-col gap-1.5">
                    <span class="text-ink-muted text-base font-bold">{{ trans('suggestions.way_in') }}</span>
                    <select v-model="originFilter" data-toolbar-control :class="SELECT_CLASS">
                        <option :value="null">{{ trans('common.all') }}</option>
                        <option v-for="origin in origins" :key="origin.key" :value="origin.key">{{ withCount(origin.label, origin.count) }}</option>
                    </select>
                </label>

                <label v-if="decades.length > 1" class="flex flex-col gap-1.5">
                    <span class="text-ink-muted text-base font-bold">{{ trans('suggestions.released_in') }}</span>
                    <select v-model="decadeFilter" data-toolbar-control :class="SELECT_CLASS">
                        <option :value="null">{{ trans('common.all') }}</option>
                        <option v-for="entry in decades" :key="entry.decade" :value="entry.decade">
                            {{ withCount(`${entry.decade}–${entry.decade + 9}`, entry.count) }}
                        </option>
                    </select>
                </label>
            </template>

            <template #sort>
                <SortControl v-model:sort="sortValue" v-model:dir="sortDir" :options="sortOptions" />
            </template>
        </PageToolbar>

        <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
            <EmptyState v-if="isEmpty" :message="trans('suggestions.nothing_on_this_shelf')" />

            <MediaGrid v-else-if="subjects !== null">
                <SubjectCard v-for="entry in subjects" :key="entry.key" :subject="entry" :href="subjectHref(entry.key)" />
            </MediaGrid>

            <MediaGrid v-else>
                <div v-for="suggestion in suggestions" :key="`${suggestion.media.type}-${suggestion.media.ref}`" class="flex h-full flex-col">
                    <MediaCard :media="suggestion.media" />
                    <!-- Why the title is here sits under the card, not in it. A
                         subject's page says it once, in its heading. -->
                    <div v-if="subject === null && suggestion.reason.length > 0" class="text-caption text-ink-muted mt-2">
                        <p v-for="fact in suggestion.reason" :key="fact" class="m-0 truncate">{{ fact }}</p>
                    </div>
                </div>
            </MediaGrid>

            <div v-if="pagination.totalPages > 1" class="mt-8">
                <Pagination :current-page="pagination.currentPage" :total-pages="pagination.totalPages" @goto="(page) => visit({ page })" />
            </div>
        </div>
    </AppLayout>
</template>
