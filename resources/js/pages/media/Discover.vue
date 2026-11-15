<script setup lang="ts">
import DiscoverBrowse from '@/components/discover/DiscoverBrowse.vue';
import DiscoverFiltered from '@/components/discover/DiscoverFiltered.vue';
import DiscoverPeople from '@/components/discover/DiscoverPeople.vue';
import PageHeading from '@/components/PageHeading.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { DISCOVER_PEOPLE, discoverTypeLabel } from '@/lib/discover';
import type { DiscoverActiveFilters, DiscoverFilter, MediaSummary } from '@/types/media';
import type { PageInfo } from '@/types/pagination';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Compass } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * One type at a time, in one of three modes: people (a person grid), filtered
 * (a provider with filtered discovery) or browse (sections and a genre
 * listing, for a provider without it). Each mode brings its own strip.
 */
const props = defineProps<{
    type: string;
    availableTypes: string[];
    trending: MediaSummary[];
    popular: MediaSummary[];
    upcoming: MediaSummary[];
    genres: { ref: string; name: string }[];
    genre: string | null;
    genreName: string | null;
    genreItems: MediaSummary[];
    genrePagination: PageInfo | null;
    /** null = this provider has no filtered discovery; the page stays the browse page. */
    filters: DiscoverFilter[] | null;
    activeFilters: DiscoverActiveFilters;
    filterCount: number | null;
    filteredItems: MediaSummary[];
    filteredPagination: PageInfo | null;
    /** `type=person`: the only entity here that is not a media type. */
    people: MediaSummary[];
    peoplePagination: PageInfo | null;
    failed: boolean;
}>();

const title = computed(() => {
    const base = discoverTypeLabel(props.type);

    return props.genreName ? trans('discover.genre_of_type', { genre: props.genreName, type: base }) : base;
});
</script>

<template>
    <AppLayout>
        <Head :title="title" />

        <PageMasthead>
            <div class="max-w-content mx-auto w-full px-4 pt-8 pb-6 md:px-10">
                <PageHeading :title="genreName ?? trans('common.discover')" :subtitle="trans('discover.browse_trending_popular_and_upcoming_titles')">
                    <template #icon><Compass class="size-6" /></template>
                </PageHeading>
            </div>
        </PageMasthead>

        <DiscoverPeople
            v-if="type === DISCOVER_PEOPLE"
            :available-types="availableTypes"
            :people="people"
            :people-pagination="peoplePagination"
            :failed="failed"
        />
        <DiscoverFiltered
            v-else-if="filters !== null"
            :type="type"
            :available-types="availableTypes"
            :trending="trending"
            :popular="popular"
            :upcoming="upcoming"
            :filters="filters"
            :active-filters="activeFilters"
            :filter-count="filterCount"
            :filtered-items="filteredItems"
            :filtered-pagination="filteredPagination"
            :failed="failed"
        />
        <DiscoverBrowse
            v-else
            :type="type"
            :available-types="availableTypes"
            :trending="trending"
            :popular="popular"
            :upcoming="upcoming"
            :genres="genres"
            :genre="genre"
            :genre-items="genreItems"
            :genre-pagination="genrePagination"
            :failed="failed"
        />
    </AppLayout>
</template>
