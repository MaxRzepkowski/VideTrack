<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import { usePosterColumns, wholeRows } from '@/composables/useShelfLimit';
import { awaitedLabel } from '@/lib/releases';
import type { MediaSummary } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    type: string;
    trending: MediaSummary[];
    popular: MediaSummary[];
    upcoming: MediaSummary[];
    /** The grid beside the filter sidebar is four wide; the full-width one is the default. */
    columns?: 4;
}>();

const sections = computed(() =>
    [
        { key: 'trending', heading: trans('discover.trending_now'), items: props.trending },
        { key: 'popular', heading: trans('discover.popular'), items: props.popular },
        { key: 'upcoming', heading: props.type === 'show' ? trans('discover.on_the_air') : trans('discover.upcoming'), items: props.upcoming },
    ].filter((section) => section.items.length > 0),
);

// The backend sends ten per section, which only splits evenly five or two wide.
// Each section trims to the last whole row of its grid; a ragged tail reads as
// a loading glitch.
const posterColumns = usePosterColumns(props.columns);
</script>

<template>
    <section v-for="section in sections" :key="section.key" class="mb-12 last:mb-0">
        <SectionHeading :title="section.heading" />
        <MediaGrid :columns="columns">
            <MediaCard
                v-for="media in wholeRows(section.items, posterColumns)"
                :key="`${section.key}-${media.type}-${media.ref}`"
                :media="media"
                :subtitle="awaitedLabel(media)"
            />
        </MediaGrid>
    </section>
</template>
