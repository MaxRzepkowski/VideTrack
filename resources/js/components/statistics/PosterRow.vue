<script setup lang="ts" generic="T extends { media: MediaSummary }">
import PosterThumb from '@/components/statistics/PosterThumb.vue';
import TypeYear from '@/components/statistics/TypeYear.vue';
import { mediaUrl } from '@/lib/urls';
import type { MediaSummary } from '@/types/generated';
import { Link } from '@inertiajs/vue3';

/**
 * A row of the period's titles as posters, each with its type and year and,
 * under them, what the row is about. Scrolls sideways where it does not fit.
 * Columns are poster-sized and as many as fit, so one or two titles keep
 * that size instead of stretching across the card; titles beyond them add
 * columns of the same size. The row is positioned so that what a title
 * hides for screen readers stays inside its scroll box instead of widening
 * the page.
 */
defineProps<{
    titles: T[];
}>();

defineSlots<{
    /** The line under a title's type and year. */
    detail(props: { title: T }): unknown;
}>();
</script>

<template>
    <ol
        class="relative m-0 grid list-none auto-cols-[minmax(8.5rem,1fr)] grid-flow-col grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-4 overflow-x-auto p-0 pb-1"
    >
        <li v-for="title in titles" :key="`${title.media.type}-${title.media.ref}`" class="min-w-0">
            <Link
                :href="mediaUrl(title.media)"
                class="group focus-visible:ring-brand flex flex-col gap-2 rounded-lg focus-visible:ring-2 focus-visible:outline-hidden"
            >
                <PosterThumb
                    :path="title.media.posterPath"
                    size="w342"
                    :width="342"
                    :height="513"
                    :widths="[185, 342]"
                    sizes="9rem"
                    class="aspect-poster w-full rounded-lg"
                />
                <span class="text-ink truncate text-base font-semibold group-hover:underline">{{ title.media.title }}</span>
                <TypeYear :type="title.media.type" :year="title.media.year" />
                <slot name="detail" :title="title" />
            </Link>
        </li>
    </ol>
</template>
