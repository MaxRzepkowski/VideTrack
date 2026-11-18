<script setup lang="ts">
import ChartTable from '@/components/statistics/ChartTable.vue';
import TypeLegend from '@/components/statistics/TypeLegend.vue';
import { inRegistryOrder, mediaTypeMeta } from '@/lib/mediaTypes';
import { countTitles, formatRating } from '@/lib/statistics';
import { fittingTicks, middleOut, RATING_LEGEND, ratingColor, releaseColumns } from '@/lib/statisticsCharts';
import type { ReleaseDot } from '@/types/generated';
import { useElementSize } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, useTemplateRef } from 'vue';

/**
 * The release year of every title of the period, one dot each, in a column
 * per year. The columns keep one height whatever the count: the busiest year
 * sets how close the dots sit, so the chart fits its card from a few dozen
 * titles to thousands. Across every type a dot takes its type's colour; in
 * one type's view, the user's rating, with the best rated in the middle.
 */
const props = defineProps<{
    dots: ReleaseDot[];
    /** The chosen type; null for every type. */
    type: string | null;
}>();

const PLOT_HEIGHT = 300;
const LARGEST_DOT = 16;

const box = useTemplateRef<HTMLElement>('box');
const { width } = useElementSize(box);

const byRating = computed(() => props.type !== null);
const typeOrder = computed(() => inRegistryOrder([...new Set(props.dots.map((dot) => dot.type))]));

const columns = computed(() => releaseColumns(props.dots, (dot) => (byRating.value ? (dot.rating ?? 0) : -typeOrder.value.indexOf(dot.type))));

const busiest = computed(() => Math.max(1, ...columns.value.map((column) => column.dots.length)));
const pitchX = computed(() => (columns.value.length === 0 ? 0 : width.value / columns.value.length));
const pitchY = computed(() => Math.min(LARGEST_DOT * 1.4, PLOT_HEIGHT / busiest.value));
const radius = computed(() => Math.max(0.5, Math.min(LARGEST_DOT, pitchX.value * 0.7, pitchY.value * 0.82) / 2));

const color = (dot: ReleaseDot): string => (byRating.value ? ratingColor(dot.rating) : mediaTypeMeta(dot.type).color);

const count = (titles: number): string => countTitles(props.type, titles);

const yearName = (year: number | null): string => (year === null ? trans('statistics.earlier') : String(year));

const describe = (column: { year: number | null; dots: ReleaseDot[] }): string => {
    const rated = column.dots.filter((dot) => dot.rating !== null).map((dot) => dot.rating ?? 0);
    const titles = count(column.dots.length);

    return rated.length === 0
        ? trans('statistics.release_column', { year: yearName(column.year), titles })
        : trans('statistics.release_column_rated', {
              year: yearName(column.year),
              titles,
              rating: formatRating(rated.reduce((sum, rating) => sum + rating, 0) / rated.length),
          });
};

/** Under the axis: the "earlier" column, every decade and the newest year, as many as fit from the newest leftwards. */
const tickLabels = computed(() =>
    columns.value.map((column, index) =>
        column.year === null || column.year % 10 === 0 || index === columns.value.length - 1 ? yearName(column.year) : null,
    ),
);
const ticked = computed(() =>
    fittingTicks(
        tickLabels.value.flatMap((label, index) => (label === null ? [] : [index])),
        pitchX.value,
        tickLabels.value.map((label) => label ?? ''),
    ),
);
const tick = (index: number): string | null => (ticked.value.has(index) ? (tickLabels.value[index] ?? null) : null);

const peak = computed(() => {
    const index = columns.value.findIndex((column) => column.dots.length === busiest.value);

    return index === -1
        ? null
        : { index, label: trans('statistics.release_column', { year: yearName(columns.value[index]?.year ?? null), titles: count(busiest.value) }) };
});
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <p v-if="byRating" class="text-ink-muted m-0 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <span>{{ trans('statistics.your_rating') }}</span>
            <span v-for="entry in RATING_LEGEND" :key="entry.label" class="text-ink-secondary flex items-center gap-1.5">
                <span class="block size-3 rounded-full" :style="{ backgroundColor: ratingColor(entry.rating) }" aria-hidden="true" />
                {{ entry.label }}
            </span>
            <span class="text-ink-secondary flex items-center gap-1.5">
                <span class="block size-3 rounded-full" :style="{ backgroundColor: ratingColor(null) }" aria-hidden="true" />
                {{ trans('statistics.not_rated') }}
            </span>
        </p>
        <TypeLegend v-else :types="typeOrder" />

        <div ref="box" class="relative w-full" :style="{ height: `${PLOT_HEIGHT + 28}px` }" aria-hidden="true">
            <svg v-if="width > 0" :width="width" :height="PLOT_HEIGHT" class="block overflow-visible">
                <line x1="0" :x2="width" :y1="PLOT_HEIGHT / 2" :y2="PLOT_HEIGHT / 2" stroke="var(--color-line-subtle)" />
                <g v-for="(column, index) in columns" :key="index">
                    <circle
                        v-for="(dot, position) in column.dots"
                        :key="position"
                        :cx="pitchX * (index + 0.5)"
                        :cy="PLOT_HEIGHT / 2 + middleOut(position) * pitchY"
                        :r="radius"
                        :fill="color(dot)"
                    />
                </g>
                <text
                    v-if="peak"
                    :x="Math.min(width - 4, pitchX * (peak.index + 0.5) - radius - 6)"
                    :y="Math.max(14, PLOT_HEIGHT / 2 - (busiest / 2) * pitchY)"
                    text-anchor="end"
                    class="fill-ink-secondary text-sm"
                >
                    {{ peak.label }}
                </text>
            </svg>
            <div class="absolute inset-x-0 top-0 flex" :style="{ height: `${PLOT_HEIGHT}px` }">
                <span
                    v-for="(column, index) in columns"
                    :key="index"
                    v-tooltip="describe(column)"
                    class="hover:bg-line-subtle/60 h-full flex-1 rounded-sm"
                />
            </div>
            <div class="text-ink-faint absolute inset-x-0 bottom-0 flex text-sm">
                <span v-for="(column, index) in columns" :key="index" class="relative flex-1">
                    <span v-if="tick(index)" class="absolute left-1/2 -translate-x-1/2 whitespace-nowrap">{{ tick(index) }}</span>
                </span>
            </div>
        </div>

        <ChartTable>
            <tbody>
                <tr v-for="(column, index) in columns.filter((entry) => entry.dots.length > 0)" :key="index">
                    <td>{{ describe(column) }}</td>
                </tr>
            </tbody>
        </ChartTable>
    </div>
</template>
