<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import type { MediaSummary } from '@/types/media';
import { Deferred } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

/** The books or manga a title is based on — a deferred prop, found through Wikidata. */
defineProps<{
    /** The deferred prop carrying the sources. */
    data: string;
    sources: MediaSummary[] | null | undefined;
    title: string;
}>();
</script>

<template>
    <Deferred :data="data">
        <template #fallback
            ><span class="sr-only">{{ trans('common.loading') }}</span></template
        >
        <section v-if="sources?.length" class="pt-16">
            <SectionHeading :title="title">
                <template #badge>
                    <span class="text-ink-faint text-sm font-medium">{{ trans('media.via_wikidata') }}</span>
                </template>
            </SectionHeading>
            <MediaGrid>
                <MediaCard v-for="source in sources" :key="source.ref" :media="source" />
            </MediaGrid>
        </section>
    </Deferred>
</template>
