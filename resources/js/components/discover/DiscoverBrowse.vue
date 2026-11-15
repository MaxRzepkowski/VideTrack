<script setup lang="ts">
import DiscoverSections from '@/components/discover/DiscoverSections.vue';
import DiscoverTypeSelect from '@/components/discover/DiscoverTypeSelect.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaCardSkeleton from '@/components/media/MediaCardSkeleton.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import { SELECT_CLASS } from '@/lib/ui';
import type { MediaSummary } from '@/types/media';
import type { PageInfo } from '@/types/pagination';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

/**
 * Discover for a provider without filtered discovery: the type and genre
 * selects as one continuous second row, the browse sections, and a paginated
 * listing once a genre is picked.
 */
const props = defineProps<{
    type: string;
    availableTypes: string[];
    trending: MediaSummary[];
    popular: MediaSummary[];
    upcoming: MediaSummary[];
    genres: { ref: string; name: string }[];
    genre: string | null;
    genreItems: MediaSummary[];
    genrePagination: PageInfo | null;
    failed: boolean;
}>();

const isEmpty = computed(
    () => props.trending.length === 0 && props.popular.length === 0 && props.upcoming.length === 0 && props.genreItems.length === 0,
);

function clearGenre(): void {
    router.get(route('discover', { type: props.type }), {}, { preserveScroll: false });
}

function onGenreChange(event: Event): void {
    const ref = (event.target as HTMLSelectElement).value;

    router.get(route('discover', ref ? { type: props.type, genre: ref } : { type: props.type }), {}, { preserveScroll: false });
}

const loading = ref(false);

function gotoPage(page: number): void {
    // Only the genre listing changes between pages — a partial reload skips
    // re-sending trending/popular/upcoming (hidden on a genre page anyway).
    router.get(
        route('discover'),
        { type: props.type, genre: props.genre, page },
        {
            preserveState: true,
            preserveScroll: false,
            only: ['genreItems', 'genrePagination', 'userStates'],
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}
</script>

<template>
    <PageToolbar variant="band" :filter-count="genre === null ? 0 : 1" @clear-filters="clearGenre">
        <template #filters>
            <DiscoverTypeSelect :type="type" :available-types="availableTypes" stacked />

            <label v-if="genres.length > 0" class="flex flex-col gap-1.5">
                <span class="text-ink-muted text-base font-bold">{{ trans('common.genre') }}</span>
                <select data-toolbar-control :value="genre ?? ''" :class="SELECT_CLASS" @change="onGenreChange">
                    <option value="">{{ trans('common.all') }}</option>
                    <option v-for="option in genres" :key="option.ref" :value="option.ref">{{ option.name }}</option>
                </select>
            </label>
        </template>
    </PageToolbar>

    <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
        <ErrorBanner v-if="failed" :message="trans('discover.could_not_load_some_sections_right')" />

        <EmptyState v-if="isEmpty && !failed && genre === null" :message="trans('discover.nothing_to_show_right_now_please')" />

        <section v-if="genre !== null">
            <MediaGrid v-if="loading">
                <MediaCardSkeleton v-for="n in 20" :key="n" />
            </MediaGrid>
            <MediaGrid v-else-if="genreItems.length > 0">
                <MediaCard v-for="media in genreItems" :key="`genre-${media.type}-${media.ref}`" :media="media" />
            </MediaGrid>
            <EmptyState v-else-if="!failed" :message="trans('discover.nothing_to_show_right_now_please')" />
            <div v-if="genrePagination" class="mt-10">
                <Pagination :current-page="genrePagination.currentPage" :total-pages="genrePagination.totalPages" @goto="gotoPage" />
            </div>
        </section>

        <DiscoverSections v-else :type="type" :trending="trending" :popular="popular" :upcoming="upcoming" />
    </div>
</template>
