<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import { usePosterColumns } from '@/composables/useShelfLimit';
import type { TitleTie } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

/**
 * The titles of this one's universe that its provider does not group with
 * it: its spin-offs, what it was spun off from, what continues it, then the
 * rest of the franchise. A card says how it stands to this title where that
 * is known. Found through Wikidata by a background task, so the page never
 * waits for it, or named by the title's own provider with its details. A
 * large universe opens two rows deep.
 */
const props = withDefaults(
    defineProps<{
        titles: TitleTie[];
        /** Who says so, beside the heading; none where the page names no source. */
        source?: string | null;
    }>(),
    { source: () => trans('media.via_wikidata') },
);

const ROWS = 2;

const columns = usePosterColumns();
const expanded = ref(false);
const limit = computed(() => columns.value * ROWS);
const shown = computed(() => (expanded.value ? props.titles : props.titles.slice(0, limit.value)));
</script>

<template>
    <section v-if="titles.length" class="pt-16">
        <SectionHeading :title="trans('media.from_the_same_universe')">
            <template v-if="source" #badge>
                <span class="text-ink-faint text-sm font-medium">{{ source }}</span>
            </template>
        </SectionHeading>
        <MediaGrid>
            <MediaCard v-for="tie in shown" :key="`${tie.relation}-${tie.media.type}-${tie.media.ref}`" :media="tie.media" :subtitle="tie.label" />
        </MediaGrid>
        <ShowMoreToggle v-if="titles.length > limit" v-model="expanded" :hidden-count="titles.length - limit" />
    </section>
</template>
