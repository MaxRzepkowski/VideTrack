<script setup lang="ts">
import DiscoverTypeSelect from '@/components/discover/DiscoverTypeSelect.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import CreatorCard from '@/components/media/CreatorCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import { DISCOVER_PEOPLE } from '@/lib/discover';
import type { MediaSummary } from '@/types/media';
import type { PageInfo } from '@/types/pagination';
import { router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    availableTypes: string[];
    people: MediaSummary[];
    peoplePagination: PageInfo | null;
    failed: boolean;
}>();

// The same counter the filtered grid wears: people are counted as items, not
// as a vocabulary of their own.
const countLabel = computed(() => (props.peoplePagination ? transChoice('common.count_item', props.peoplePagination.total) : null));

function gotoPage(page: number): void {
    router.get(route('discover', { type: DISCOVER_PEOPLE, page }), {}, { preserveScroll: false });
}
</script>

<template>
    <!-- The same type select and a count, nothing else — there are no
         genres, filters or sections behind this value. -->
    <PageToolbar variant="band" :count="countLabel" :filter-count="null">
        <template #search>
            <DiscoverTypeSelect :type="DISCOVER_PEOPLE" :available-types="availableTypes" />
        </template>
    </PageToolbar>

    <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
        <ErrorBanner v-if="failed" :message="trans('discover.could_not_load_some_sections_right')" />

        <!-- People carry no watched state, so the only actions are the list
             membership and the bell. -->
        <section>
            <MediaGrid v-if="people.length > 0">
                <CreatorCard v-for="person in people" :key="`person-${person.ref}`" :media="person" />
            </MediaGrid>
            <EmptyState v-else-if="!failed" :message="trans('discover.nothing_to_show_right_now_please')" />

            <div v-if="peoplePagination && peoplePagination.totalPages > 1" class="mt-10">
                <Pagination :current-page="peoplePagination.currentPage" :total-pages="peoplePagination.totalPages" @goto="gotoPage" />
            </div>
        </section>
    </div>
</template>
