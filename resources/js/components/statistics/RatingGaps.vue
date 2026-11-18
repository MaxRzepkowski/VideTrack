<script setup lang="ts">
import { formatNumber } from '@/lib/format';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { RATING_MAX, RATING_MIN } from '@/lib/ratings';
import { ratingShare } from '@/lib/statisticsCharts';
import { mediaUrl } from '@/lib/urls';
import type { RatingGap } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

/**
 * One side of the disagreements: the titles the user rated furthest above
 * (or below) everyone else, each with both ratings and the two as marks on
 * one track along the rating scale.
 */
defineProps<{
    heading: string;
    /** Said in place of the list when no title is on this side. */
    none: string;
    titles: RatingGap[];
}>();

/** Where a rating sits along the track, as a share of its width. */
const along = (value: number): string => `${ratingShare(value) * 100}%`;
</script>

<template>
    <div class="flex min-w-0 flex-col gap-3">
        <div class="flex items-baseline justify-between gap-4">
            <h4 class="text-ink m-0 text-base font-semibold">{{ heading }}</h4>
            <span v-if="titles.length > 0" class="text-ink-faint flex w-32 shrink-0 justify-between text-sm" aria-hidden="true">
                <span>{{ RATING_MIN }}</span>
                <span>{{ RATING_MAX }}</span>
            </span>
        </div>
        <p v-if="titles.length === 0" class="text-ink-muted m-0 text-base">{{ none }}</p>
        <ol v-else class="m-0 flex list-none flex-col gap-3 p-0">
            <li v-for="title in titles" :key="`${title.media.type}-${title.media.ref}`" class="flex items-center gap-3">
                <component
                    :is="mediaTypeMeta(title.media.type).icon"
                    class="size-4 shrink-0"
                    :style="{ color: mediaTypeMeta(title.media.type).color }"
                    aria-hidden="true"
                />
                <span class="flex min-w-0 flex-1 flex-col">
                    <Link
                        :href="mediaUrl(title.media)"
                        class="text-ink-secondary focus-visible:ring-brand truncate rounded text-base hover:underline focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        {{ title.media.title }}
                    </Link>
                    <span class="text-ink-muted text-sm tabular-nums">
                        {{ trans('statistics.gap_values', { yours: formatNumber(title.rating), others: formatNumber(title.community, 1) }) }}
                    </span>
                </span>
                <svg class="h-3.5 w-32 shrink-0 overflow-visible" aria-hidden="true">
                    <line x1="0" x2="100%" y1="50%" y2="50%" stroke="var(--color-line)" stroke-width="2" stroke-linecap="round" />
                    <line :x1="along(title.community)" :x2="along(title.rating)" y1="50%" y2="50%" stroke="var(--color-ink-faint)" stroke-width="2" />
                    <circle
                        :cx="along(title.community)"
                        cy="50%"
                        r="4"
                        fill="var(--color-surface)"
                        stroke="var(--color-ink-faint)"
                        stroke-width="2"
                    />
                    <circle :cx="along(title.rating)" cy="50%" r="5" fill="var(--color-brand-soft)" />
                </svg>
            </li>
        </ol>
    </div>
</template>
