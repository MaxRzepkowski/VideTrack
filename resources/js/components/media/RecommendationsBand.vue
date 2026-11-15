<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import PageSection from '@/components/PageSection.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import { usePosterShelf } from '@/composables/useShelfLimit';
import type { MediaSummary } from '@/types/media';

/**
 * Related and similar titles in one band: as separate bands their paddings
 * would stack into a gap twice the size of the one inside the band.
 */
const props = withDefaults(
    defineProps<{
        related?: MediaSummary[];
        relatedTitle?: string;
        similar?: MediaSummary[];
        similarTitle?: string;
        /** About how many posters a shelf shows, rounded up to a whole row. */
        shelfSize?: number;
    }>(),
    { related: () => [], relatedTitle: '', similar: () => [], similarTitle: '', shelfSize: 5 },
);

const shelf = usePosterShelf(props.shelfSize);
</script>

<template>
    <PageSection v-if="related.length || similar.length" tone="subtle">
        <section v-if="related.length">
            <SectionHeading :title="relatedTitle" />
            <MediaGrid>
                <MediaCard v-for="media in shelf(related)" :key="media.ref" :media="media" />
            </MediaGrid>
        </section>

        <section v-if="similar.length" :class="related.length ? 'mt-10' : ''">
            <SectionHeading :title="similarTitle" />
            <MediaGrid>
                <MediaCard v-for="media in shelf(similar)" :key="media.ref" :media="media" />
            </MediaGrid>
        </section>
    </PageSection>
</template>
