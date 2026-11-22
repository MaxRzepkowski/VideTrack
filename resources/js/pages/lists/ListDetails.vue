<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import CollectionFacts from '@/components/lists/CollectionFacts.vue';
import CollectionSeriesLinks from '@/components/lists/CollectionSeriesLinks.vue';
import ListHeaderActions from '@/components/lists/ListHeaderActions.vue';
import ListStatistics from '@/components/lists/ListStatistics.vue';
import CastSection from '@/components/media/CastSection.vue';
import CollectionRatingsSection from '@/components/media/CollectionRatingsSection.vue';
import CreatorCard from '@/components/media/CreatorCard.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import PhotosSection from '@/components/media/PhotosSection.vue';
import PosterArt from '@/components/media/PosterArt.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import ReorderControls from '@/components/ReorderControls.vue';
import SearchInput from '@/components/SearchInput.vue';
import SortControl from '@/components/SortControl.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import { useListActions } from '@/composables/useListActions';
import { useListBrowse } from '@/composables/useListBrowse';
import { useManualOrder } from '@/composables/useManualOrder';
import { useMediaActions } from '@/composables/useMediaActions';
import { useReloadAfterWrites } from '@/composables/useReloadAfterWrites';
import AppLayout from '@/layouts/AppLayout.vue';
import { leafCode, showTitleOf } from '@/lib/badges';
import { creatorOf } from '@/lib/creators';
import { formatTimestampDate, formatTotalRuntime, getInitials } from '@/lib/format';
import { posterGridClass, SELECT_CLASS } from '@/lib/ui';
import type { CollectionCredit, CollectionOverview } from '@/types/generated';
import type { ListDetailsData, ListItemEntry, ListStatisticsData } from '@/types/media';
import { Head, Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Globe, GripVertical, Lock, X } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    list: ListDetailsData;
    items: ListItemEntry[];
    stats: ListStatisticsData;
    /** The saga as a whole; null for a list a user typed out. */
    collectionOverview: CollectionOverview | null;
}>();

// Positions and the statistics follow what is done to them.
useReloadAfterWrites(['items', 'stats', 'userStates']);

const { stateOf } = useMediaActions();
const lists = useListActions();

const title = computed(() => props.list.displayTitle);

// The rating grid is a collection-only overview (a franchise's quality arc);
// plain user lists mix types and orderings, so it stays off there.
const isCollection = computed(() => props.list.collection !== null);

// A collection holds its parts alone, so every position is one of them.
const collectionParts = computed(() => props.items.map((item) => item.media));

// Under a person in the collection's cast: in how many of its movies.
function filmCount(credit: CollectionCredit): string {
    return transChoice('common.count_movie', credit.titleCount);
}

// Reordering, by drag & drop or the step buttons; only in List order.
const { orderedIds, dragId, startDrag, endDrag, dropOn, move, moveToEdge } = useManualOrder(
    () => props.items.map((item) => item.id),
    (ids, onError) =>
        // The move did not stick: put it back (the store has said so).
        void lists.reorderItems(props.list.id, ids).then((landed) => landed || onError()),
);

// Edit mode: the whole list in its manual order, with the move controls.
const editing = ref(false);

const {
    sort,
    sortDir,
    searchQuery,
    typeFilter,
    genreFilter,
    fromDate,
    toDate,
    hideRated,
    hideCompleted,
    typeOptions: typeFilterOptions,
    genreOptions,
    activeFilterCount,
    clearFilters,
    displayed,
    page,
    totalPages,
    paged,
    isFirst,
    isLast,
} = useListBrowse(
    () => props.items,
    orderedIds,
    editing,
    (item) => stateOf(item.media).value,
);

const sortOptions = computed(() => [
    { value: 'order', label: trans('lists.list_order') },
    { value: 'added', label: trans('common.date_added') },
    { value: 'rating', label: trans('common.my_rating') },
    { value: 'community', label: trans('common.community_rating') },
    { value: 'release', label: trans('common.release_date') },
    { value: 'total_runtime', label: trans('common.total_runtime') },
    { value: 'az', label: trans('lists.a_z') },
]);

const listTop = ref<HTMLElement | null>(null);

function goToPage(next: number): void {
    page.value = next;
    listTop.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ---- Card context ----

// A season or an episode names the show it belongs to.
function subtitle(item: ListItemEntry): string | null {
    return showTitleOf(item.media);
}

// The line under a card: whatever the active sort ordered by, spelled out, so
// the sequence is readable rather than taken on trust. Only the axes whose
// value is not already on the card carry one.
function cardMeta(item: ListItemEntry): string | null {
    if (sort.value === 'added') {
        return formatTimestampDate(item.addedAt);
    }

    return sort.value === 'total_runtime' ? formatTotalRuntime(item.media.totalRuntime) : null;
}

// The row is reserved for the whole page as soon as one card fills it, so the
// cards that have nothing to say there stay level with the rest.
const showsCardMeta = computed(() => paged.value.some((item) => cardMeta(item) !== null));

// The entry leaves the grid with the answer, not ahead of it.
function removeItem(id: number): void {
    void lists.removeItem(props.list.id, id);
}
</script>

<template>
    <AppLayout>
        <Head :title="title" />

        <PageMasthead>
            <!-- The same compact header the user panels wear, with the list's
                 own identity: its title and its author. -->
            <PageHeader :title="title">
                <template v-if="collectionOverview && list.collection" #title-badge>
                    <CollectionFacts :overview="collectionOverview" :collection="list.collection" :part-type="items[0]?.media.type ?? null" />
                </template>

                <template #meta>
                    <Link
                        v-if="list.owner"
                        :href="route('users.show', { user: list.owner.username })"
                        class="group border-line bg-surface flex h-9 items-center gap-2 rounded-full border py-1 pr-3.5 pl-1"
                    >
                        <div
                            class="from-brand to-brand-strong text-initials text-on-accent flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br font-bold"
                        >
                            <img
                                v-if="list.owner.avatar"
                                :src="list.owner.avatar"
                                :alt="list.owner.name"
                                width="28"
                                height="28"
                                loading="lazy"
                                class="h-full w-full object-cover"
                            />
                            <span v-else>{{ getInitials(list.owner.name) }}</span>
                        </div>
                        <span class="text-ink-muted group-hover:text-ink text-base font-semibold transition-colors duration-150">{{
                            list.owner.name
                        }}</span>
                    </Link>
                    <!-- Ownerless system list (provider collection): authored by the instance. -->
                    <div v-else class="border-line bg-surface flex h-9 items-center gap-2 rounded-full border py-1 pr-3.5 pl-1">
                        <div
                            class="from-brand to-brand-strong text-initials text-on-accent flex size-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br font-bold"
                        >
                            V
                        </div>
                        <span class="text-ink-muted text-base font-semibold">VideTrack</span>
                    </div>
                    <span
                        class="border-line bg-surface text-ink-muted flex h-9 items-center gap-1.5 rounded-full border px-3.5 text-base font-semibold"
                    >
                        <Globe v-if="list.isPublic" class="size-3.5" :stroke-width="2.5" />
                        <Lock v-else class="size-3.5" :stroke-width="2.5" />
                        {{ list.isPublic ? trans('lists.public') : trans('common.private') }}
                    </span>
                </template>

                <template #actions>
                    <ListHeaderActions v-model:editing="editing" :list="list" :item-count="items.length" :positions="displayed" />
                </template>

                <template #nav>
                    <div class="pb-6">
                        <p v-if="list.description" class="text-ink-muted max-w-reading-lg m-0 mb-5 text-base">{{ list.description }}</p>
                        <!-- A series names the series above it and the ones under it, as its provider does. -->
                        <CollectionSeriesLinks
                            v-if="list.collection && list.collectionRelations"
                            :provider="list.collection.provider"
                            :relations="list.collectionRelations"
                        />
                        <ListStatistics :stats="stats" />
                    </div>
                </template>
            </PageHeader>
        </PageMasthead>

        <!-- Edit mode is reordering, not browsing: the controls that would hide
             a position while you drag it step aside, panel included
             (`filter-count` null = no button). -->
        <PageToolbar
            variant="band"
            :count="transChoice('common.count_item', displayed.length)"
            :filter-count="editing ? null : activeFilterCount"
            @clear-filters="clearFilters"
        >
            <template v-if="!editing" #search>
                <SearchInput v-model="searchQuery" />
            </template>

            <template #filters>
                <TypeFilter v-model="typeFilter" :options="typeFilterOptions" :total="items.length" :labelled="true" />

                <label v-if="genreOptions.length > 0" class="flex flex-col gap-1.5">
                    <span class="text-ink-muted text-base font-bold">{{ trans('common.genre') }}</span>
                    <select v-model="genreFilter" data-toolbar-control :class="SELECT_CLASS">
                        <option :value="null">{{ trans('common.all') }}</option>
                        <option v-for="genre in genreOptions" :key="genre" :value="genre">{{ genre }}</option>
                    </select>
                </label>

                <!-- The date range runs on when the position was added,
                     the only date a list has of its own. -->
                <label class="flex flex-col gap-1.5">
                    <span class="text-ink-muted text-base font-bold">{{ trans('common.from') }}</span>
                    <input v-model="fromDate" data-toolbar-control type="date" :class="SELECT_CLASS" />
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-ink-muted text-base font-bold">{{ trans('common.to') }}</span>
                    <input v-model="toDate" data-toolbar-control type="date" :class="SELECT_CLASS" />
                </label>

                <!-- Native boxes, not ui/checkbox: their `change` is what folds
                     the filter panel away, as a select or a date does. -->
                <div class="flex flex-wrap items-center gap-4 pb-1">
                    <label class="text-ink-secondary flex cursor-pointer items-center gap-2 text-base font-semibold">
                        <input v-model="hideCompleted" type="checkbox" class="accent-brand size-4" />
                        {{ trans('common.hide_completed') }}
                    </label>
                    <label class="text-ink-secondary flex cursor-pointer items-center gap-2 text-base font-semibold">
                        <input v-model="hideRated" type="checkbox" class="accent-brand size-4" />
                        {{ trans('common.hide_rated') }}
                    </label>
                </div>
            </template>

            <template #sort>
                <SortControl v-if="!editing && items.length > 1" v-model:sort="sort" v-model:dir="sortDir" :options="sortOptions" />
            </template>
        </PageToolbar>

        <!-- Plain element for the scroll anchor: a ref on the component would
             hand back an instance, not something to scroll to. It rides on the
             results, and `scroll-mt` clears the app header and the band above
             them — otherwise a page jump lands the first row underneath. -->
        <div ref="listTop" class="max-w-content mx-auto w-full flex-1 scroll-mt-32 px-4 pb-20 md:scroll-mt-40 md:px-10">
            <EmptyState v-if="items.length === 0" :message="trans('lists.this_list_is_empty')" />

            <!-- Edit mode: reorder only in List order; other sorts are remove-only. -->
            <!-- Reorder/remove animate: FLIP slides the survivors into place. -->
            <TransitionGroup v-else-if="editing" tag="div" name="reorder" :class="posterGridClass()">
                <div
                    v-for="item in paged"
                    :key="item.id"
                    :draggable="sort === 'order'"
                    class="flex flex-col"
                    :class="{ 'opacity-40': dragId === item.id }"
                    @dragstart="startDrag($event, item.id)"
                    @dragend="endDrag"
                    @dragover.prevent
                    @drop.prevent="dropOn(item.id)"
                >
                    <div class="border-line-subtle bg-surface ring-brand/40 xs:rounded-2xl flex flex-col overflow-hidden rounded-xl border ring-2">
                        <PosterArt :media="item.media" :corner-badge="leafCode(item.media)" :class="sort === 'order' ? 'cursor-grab' : ''">
                            <div class="bg-scrim/25 absolute inset-0 flex items-start justify-between p-2">
                                <div
                                    v-if="sort === 'order'"
                                    class="text-ink-secondary bg-scrim/70 flex size-7 items-center justify-center rounded-md"
                                >
                                    <GripVertical class="size-3.5" />
                                </div>
                                <div v-else></div>
                                <button
                                    v-tooltip="trans('lists.remove_from_list')"
                                    :aria-label="trans('lists.remove_from_list')"
                                    type="button"
                                    class="text-danger hover:bg-danger hover:text-danger-surface bg-scrim/70 flex size-7 items-center justify-center rounded-md transition-colors duration-150"
                                    @click="removeItem(item.id)"
                                >
                                    <X class="size-3.5" :stroke-width="2.4" />
                                </button>
                            </div>
                            <ReorderControls
                                v-if="sort === 'order'"
                                :first="isFirst(item)"
                                :last="isLast(item)"
                                @move="(delta) => move(item.id, delta)"
                                @edge="(edge) => moveToEdge(item.id, edge)"
                            />
                        </PosterArt>
                        <div class="p-3 text-center">
                            <div v-tooltip.truncated="item.media.title" class="text-ink truncate text-base font-bold">{{ item.media.title }}</div>
                        </div>
                    </div>
                </div>
            </TransitionGroup>

            <!-- The list has positions, the controls left none of them — the
                 log panels' wording for the same situation. -->
            <EmptyState v-else-if="displayed.length === 0" :message="trans('common.nothing_here_yet')" />

            <!-- View mode: full action cards -->
            <MediaGrid v-else>
                <template v-for="item in paged" :key="item.id">
                    <!-- People carry no watched / rating / list state — a
                         portrait card instead of the action-bar card. -->
                    <div class="flex flex-col">
                        <CreatorCard v-if="creatorOf(item.media)" :media="item.media" />
                        <MediaCard v-else :media="item.media" :subtitle="subtitle(item)" :corner-badge="leafCode(item.media)" />
                        <!-- Under the card, not on it: what the active sort
                             ordered by. Once one card carries the line every
                             card holds its row — a person has no runtime, and
                             the grid must not step around them. -->
                        <div v-if="showsCardMeta" class="text-ink-faint mt-2 min-h-5 text-center text-sm font-semibold">{{ cardMeta(item) }}</div>
                    </div>
                </template>
            </MediaGrid>

            <Pagination v-if="displayed.length > 0" :current-page="page" :total-pages="totalPages" class="mt-10" @goto="goToPage" />

            <!-- The people the saga keeps coming back to, the whole width: a
                 title's page has the cast in its narrow column, a collection
                 has no column beside its grid. -->
            <CastSection
                v-if="collectionOverview"
                :cast="collectionOverview.cast"
                :crew="collectionOverview.crew"
                :caption="filmCount"
                class="mt-10"
            />

            <!-- Collection quality arc (community ratings); hidden for plain lists -->
            <CollectionRatingsSection v-if="isCollection" :parts="collectionParts" class="mt-10" />

            <!-- The saga's gallery, the section a title's page carries. A list
                 a user typed out has no images of its own, so it renders
                 nothing — which is what keeps the two pages one page. -->
            <PhotosSection :backdrops="list.backdrops" :posters="list.posters" class="mt-10" />
        </div>
    </AppLayout>
</template>
