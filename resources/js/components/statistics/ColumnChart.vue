<script setup lang="ts">
import { formatNumber } from '@/lib/format';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * Vertical bars over a row of labels: months of a year, hours of a day, days
 * of a week. Drawn with boxes, so it stretches to its card. The values are
 * also a table for screen readers; the bars themselves are decorative.
 */
const props = withDefaults(
    defineProps<{
        values: number[];
        /** One per bar: what it counts, for the table and the tooltip. */
        labels: string[];
        /** Which bars get their label printed under the axis. */
        ticks?: (index: number) => boolean;
        /** What is printed under the axis, when shorter than `labels` ("Jun" for "June 2025"). */
        tickLabels?: string[] | null;
        /** `var(--color-…)` of the bars. */
        color: string;
        /** A bar drawn in `highlightColor` (the busiest one). */
        highlight?: number | null;
        highlightColor?: string;
        /** Print each value above its bar. */
        showValues?: boolean;
        /** The axis says its top value at the side. */
        axis?: boolean;
        /** Plot height in pixels. */
        height?: number;
        caption: string;
    }>(),
    {
        ticks: () => true,
        tickLabels: null,
        highlight: null,
        highlightColor: 'var(--color-brand-soft)',
        showValues: false,
        axis: false,
        height: 96,
    },
);

const max = computed(() => Math.max(1, ...props.values));

const barHeight = (value: number): string => (value === 0 ? '0' : `max(2px, ${(value / max.value) * 100}%)`);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2">
        <div class="flex min-w-0 gap-2" aria-hidden="true">
            <div
                v-if="axis"
                class="text-ink-faint flex flex-col justify-between text-right text-sm leading-none tabular-nums"
                :style="{ height: `${height}px` }"
            >
                <span>{{ formatNumber(max) }}</span>
                <span>0</span>
            </div>
            <div class="flex min-w-0 flex-1 flex-col">
                <div
                    class="border-chart-line relative flex items-end gap-[3px] border-b"
                    :class="axis ? 'border-t-line-subtle border-t' : ''"
                    :style="{ height: `${height}px` }"
                >
                    <div
                        v-for="(value, index) in values"
                        :key="index"
                        v-tooltip="trans('statistics.chart_value', { label: labels[index] ?? '', value: formatNumber(value) })"
                        class="relative flex h-full min-w-0 flex-1 items-end"
                    >
                        <span
                            v-if="showValues"
                            class="text-ink-secondary absolute inset-x-0 text-center text-sm tabular-nums"
                            :style="{ bottom: `calc(${value === 0 ? '0px' : barHeight(value)} + 4px)` }"
                        >
                            {{ formatNumber(value) }}
                        </span>
                        <span
                            class="block w-full rounded-t-[2px]"
                            :style="{ height: barHeight(value), backgroundColor: index === highlight ? highlightColor : color }"
                        />
                    </div>
                </div>
                <div class="text-ink-faint mt-2 flex gap-[3px] text-sm">
                    <span
                        v-for="(label, index) in tickLabels ?? labels"
                        :key="index"
                        class="min-w-0 flex-1 overflow-visible text-center whitespace-nowrap"
                    >
                        {{ ticks(index) ? label : '' }}
                    </span>
                </div>
            </div>
        </div>
        <table class="sr-only">
            <caption>
                {{
                    caption
                }}
            </caption>
            <tbody>
                <tr v-for="(label, index) in labels" :key="index">
                    <th scope="row">{{ label }}</th>
                    <td>{{ formatNumber(values[index] ?? 0) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
