<script setup lang="ts">
import ChartTable from '@/components/statistics/ChartTable.vue';
import TypeLegend from '@/components/statistics/TypeLegend.vue';
import { formatNumber } from '@/lib/format';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { RATING_MAX, RATING_MIN } from '@/lib/ratings';
import { countTitles } from '@/lib/statistics';
import { ratingRows, ratingShare, rowSpread } from '@/lib/statisticsCharts';
import type { RatingPoint } from '@/types/generated';
import { useElementSize } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, useTemplateRef } from 'vue';

/**
 * The user's rating of each title against everyone else's average, a dot
 * per title: above the diagonal the user rated it higher, below it lower.
 * The user's ratings are whole numbers, so each sits in a band of its own,
 * its dots spread across it; the hover tells a band's count and the others'
 * average over it, the dots being too many to tell apart.
 */
const props = defineProps<{
    points: RatingPoint[];
    /** The chosen type; null for every type. */
    type: string | null;
}>();

const HEIGHT = 420;
const PAD = { top: 32, right: 12, bottom: 56, left: 32 };
const DOT_RADIUS = 3.5;
/** How much of a band the spread of its dots takes up. */
const BAND_FILL = 0.7;

const box = useTemplateRef<HTMLElement>('box');
const { width } = useElementSize(box);

const span = RATING_MAX - RATING_MIN;
const plotWidth = computed(() => Math.max(0, width.value - PAD.left - PAD.right));
const plotHeight = HEIGHT - PAD.top - PAD.bottom;
const band = plotHeight / span;

/** Both axes run the user's scale; the rare average below its bottom sits on it. */
const x = (value: number): number => PAD.left + ratingShare(value) * plotWidth.value;
const y = (value: number): number => PAD.top + ((RATING_MAX - value) / span) * plotHeight;

const ratings = Array.from({ length: span + 1 }, (_, index) => RATING_MIN + index);
/** Every other rating under the axis of everyone else's average, which reads with decimals. */
const othersTicks = ratings.filter((rating) => rating % 2 === 0);

const dots = computed(() => {
    const placed: { cx: number; cy: number; color: string }[] = [];
    const inRow = new Map<number, number>();

    for (const point of [...props.points].sort((a, b) => a.community - b.community)) {
        for (let title = 0; title < point.titles; title++) {
            const index = inRow.get(point.rating) ?? 0;
            inRow.set(point.rating, index + 1);
            placed.push({
                cx: x(point.community),
                cy: y(point.rating) + rowSpread(index) * band * BAND_FILL,
                color: mediaTypeMeta(point.type).color,
            });
        }
    }

    return placed;
});

const rows = computed(() => ratingRows(props.points));

const describe = (row: { rating: number; titles: number; community: number }): string =>
    trans('statistics.scatter_row', {
        rating: String(row.rating),
        titles: countTitles(props.type, row.titles),
        community: formatNumber(row.community, 1),
    });

/** The diagonal's slope on screen, for the label that runs along it. */
const diagonalAngle = computed(() => (plotWidth.value === 0 ? 0 : (-Math.atan(plotHeight / plotWidth.value) * 180) / Math.PI));
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <TypeLegend :types="points.map((point) => point.type)" />

        <div ref="box" class="relative w-full" :style="{ height: `${HEIGHT}px` }" aria-hidden="true">
            <svg v-if="width > 0" :width="width" :height="HEIGHT" class="block overflow-visible">
                <g v-for="rating in ratings" :key="rating">
                    <line
                        :x1="PAD.left"
                        :x2="PAD.left + plotWidth"
                        :y1="y(rating)"
                        :y2="y(rating)"
                        :stroke="rating === RATING_MIN ? 'var(--color-chart-line)' : 'var(--color-line-faint)'"
                    />
                    <text :x="PAD.left - 10" :y="y(rating)" text-anchor="end" dominant-baseline="central" class="fill-ink-faint text-sm">
                        {{ rating }}
                    </text>
                </g>
                <text
                    v-for="tick in othersTicks"
                    :key="tick"
                    :x="x(tick)"
                    :y="HEIGHT - PAD.bottom + 22"
                    text-anchor="middle"
                    class="fill-ink-faint text-sm"
                >
                    {{ tick }}
                </text>

                <line
                    :x1="x(RATING_MIN)"
                    :y1="y(RATING_MIN)"
                    :x2="x(RATING_MAX)"
                    :y2="y(RATING_MAX)"
                    stroke="var(--color-ink-faint)"
                    stroke-dasharray="6 6"
                />

                <circle v-for="(dot, index) in dots" :key="index" :cx="dot.cx" :cy="dot.cy" :r="DOT_RADIUS" :fill="dot.color" fill-opacity="0.85" />

                <text :x="PAD.left" :y="PAD.top - 16" class="fill-ink-faint text-sm">{{ trans('statistics.scatter_yours') }}</text>
                <text :x="PAD.left + plotWidth" :y="HEIGHT - 6" text-anchor="end" class="fill-ink-faint text-sm">
                    {{ trans('statistics.scatter_others') }}
                </text>
                <text :x="x(RATING_MIN + 0.4)" :y="y(RATING_MAX - 0.6)" class="fill-ink-muted text-sm">
                    {{ trans('statistics.you_rate_higher') }}
                </text>
                <text :x="x(RATING_MAX - 0.2)" :y="y(RATING_MIN + 0.6)" text-anchor="end" class="fill-ink-muted text-sm">
                    {{ trans('statistics.you_rate_lower') }}
                </text>
                <text
                    :transform="`translate(${x(RATING_MIN + 1.6)} ${y(RATING_MIN + 1.6) - 8}) rotate(${diagonalAngle})`"
                    class="fill-ink-muted text-sm"
                >
                    {{ trans('statistics.same_as_others') }}
                </text>
            </svg>

            <span
                v-for="row in rows"
                :key="row.rating"
                v-tooltip="describe(row)"
                class="hover:bg-line-subtle/40 absolute rounded-sm"
                :style="{ left: `${PAD.left}px`, right: `${PAD.right}px`, top: `${y(row.rating) - band / 2}px`, height: `${band}px` }"
            />
        </div>

        <ChartTable>
            <tbody>
                <tr v-for="row in rows" :key="row.rating">
                    <td>{{ describe(row) }}</td>
                </tr>
            </tbody>
        </ChartTable>
    </div>
</template>
