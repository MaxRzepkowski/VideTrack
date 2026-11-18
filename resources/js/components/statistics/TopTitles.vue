<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import { formatRuntime } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { leafType, mediaTypeMeta } from '@/lib/mediaTypes';
import { formatUnitAmount, type ReadUnit } from '@/lib/progress';
import { mediaUrl } from '@/lib/urls';
import type { TimeTitle } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

/**
 * The titles the most time went to, as a row of posters: each with its type
 * and year, its time (or what was read of it) and, for a show, how many of
 * its episodes. Scrolls sideways where the row does not fit.
 */
defineProps<{
    titles: TimeTitle[];
    /** The unit the amounts are read in, for one type that is read; null when they are time. */
    readIn: ReadUnit | null;
}>();

/**
 * What the title took: its time, or what was read of it. Reading counted as
 * time is an estimate, and says so; read in its own unit, the estimate
 * follows it.
 */
const amount = (title: TimeTitle, readIn: ReadUnit | null): string => {
    if (readIn !== null) {
        return formatUnitAmount(readIn, title.amount);
    }

    const time = formatRuntime(title.amount) ?? '';

    return title.estimatedMinutes === null ? time : trans('statistics.estimated_value', { value: time });
};

const estimate = (title: TimeTitle, readIn: ReadUnit | null): string | null =>
    readIn === null || title.estimatedMinutes === null || title.estimatedMinutes < 1
        ? null
        : trans('statistics.estimated_value', { value: formatRuntime(title.estimatedMinutes) ?? '' });
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
                <span class="flex flex-wrap items-center gap-x-2 text-sm">
                    <span class="text-ink-secondary text-base font-semibold">{{ amount(title, readIn) }}</span>
                    <template v-if="estimate(title, readIn)">
                        <Hairline class="mx-0" />
                        <span class="text-ink-muted">{{ estimate(title, readIn) }}</span>
                    </template>
                    <template v-if="title.parts !== null">
                        <Hairline class="mx-0" />
                        <span class="text-ink-muted">{{ mediaTypeMeta(leafType(title.media.type)).count(title.parts) }}</span>
                    </template>
                </span>
            </Link>
        </li>
    </ol>
</template>
