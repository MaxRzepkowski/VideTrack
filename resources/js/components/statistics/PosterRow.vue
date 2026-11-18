<script setup lang="ts" generic="T extends { media: MediaSummary }">
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { mediaUrl } from '@/lib/urls';
import type { MediaSummary } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

/**
 * A row of the period's titles as posters, each with its type and year and,
 * under them, what the row is about. Scrolls sideways where it does not fit.
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
    <ol class="m-0 grid list-none auto-cols-[minmax(8.5rem,1fr)] grid-flow-col gap-4 overflow-x-auto p-0 pb-1">
        <li v-for="title in titles" :key="`${title.media.type}-${title.media.ref}`" class="min-w-0">
            <Link
                :href="mediaUrl(title.media)"
                class="group focus-visible:ring-brand flex flex-col gap-2 rounded-lg focus-visible:ring-2 focus-visible:outline-hidden"
            >
                <img
                    v-if="mediaImage(title.media.posterPath, 'w342')"
                    :src="mediaImage(title.media.posterPath, 'w342') ?? undefined"
                    :srcset="mediaImageSrcSet(title.media.posterPath, [185, 342])"
                    sizes="9rem"
                    alt=""
                    width="342"
                    height="513"
                    loading="lazy"
                    class="bg-surface-raised aspect-poster w-full rounded-lg object-cover"
                />
                <span v-else class="bg-surface-raised aspect-poster block w-full rounded-lg" aria-hidden="true" />
                <span class="text-ink truncate text-base font-semibold group-hover:underline">{{ title.media.title }}</span>
                <span class="text-ink-muted flex items-center gap-1.5 text-sm">
                    <component
                        :is="mediaTypeMeta(title.media.type).icon"
                        class="size-4 shrink-0"
                        :style="{ color: mediaTypeMeta(title.media.type).color }"
                        aria-hidden="true"
                    />
                    <span class="truncate">
                        {{
                            title.media.year === null
                                ? mediaTypeMeta(title.media.type).label()
                                : trans('statistics.type_and_year', { type: mediaTypeMeta(title.media.type).label(), year: String(title.media.year) })
                        }}
                    </span>
                </span>
                <slot name="detail" :title="title" />
            </Link>
        </li>
    </ol>
</template>
