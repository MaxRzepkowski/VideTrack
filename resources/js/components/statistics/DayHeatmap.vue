<script setup lang="ts">
import { formatDayKey, formatNumber } from '@/lib/format';
import { calendarWeeks, DAY_THRESHOLDS, monthName, scaleColor, scaleLegend, scaleStep, weekdayNames } from '@/lib/statisticsCharts';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * Every day of a period as a square in a wall-calendar grid, the user's first weekday on top,
 * coloured by how much was finished that day. The steps are fixed counts,
 * not a share of the busiest day, so one day of a bulk entry cannot pale the
 * rest of the year.
 */
const props = defineProps<{
    from: string;
    to: string;
    days: Record<string, number>;
    /** The weekday the user's week opens on (ISO: 1 is Monday). */
    firstWeekday: number;
    /** The legend's caption: "Completed in a day:". */
    legendLabel: string;
}>();

const grid = computed(() => calendarWeeks(props.from, props.to, props.days, props.firstWeekday));
const weekdays = computed(() => weekdayNames('short', props.firstWeekday));
const legend = computed(() => scaleLegend(DAY_THRESHOLDS, (value) => trans('statistics.value_or_more', { value: String(value) })));

const tooltip = (day: string, count: number): string =>
    trans('statistics.chart_value', { label: formatDayKey(day) ?? '', value: formatNumber(count) });
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <div class="overflow-x-auto pb-1" aria-hidden="true">
            <div class="inline-grid grid-cols-[auto_auto] gap-x-2">
                <span />
                <div class="relative h-5">
                    <span
                        v-for="month in grid.months"
                        :key="`${month.column}-${month.month}`"
                        class="text-ink-faint absolute top-0 text-sm"
                        :style="{ left: `${month.column * 22}px` }"
                    >
                        {{ monthName(month.month) }}
                    </span>
                </div>
                <div class="text-ink-faint mt-1 grid grid-rows-7 gap-1 text-sm leading-[18px]">
                    <span v-for="(name, index) in weekdays" :key="name">{{ index % 2 === 0 ? name : '' }}</span>
                </div>
                <div class="mt-1 flex gap-1">
                    <div v-for="(week, column) in grid.weeks" :key="column" class="grid grid-rows-7 gap-1">
                        <span
                            v-for="(cell, row) in week"
                            :key="row"
                            v-tooltip="cell.day === null ? null : tooltip(cell.day, cell.count)"
                            class="block size-[18px] rounded-[3px]"
                            :class="cell.day === null ? 'invisible' : ''"
                            :style="{ backgroundColor: scaleColor(scaleStep(cell.count, DAY_THRESHOLDS)) }"
                        />
                    </div>
                </div>
            </div>
        </div>

        <p class="text-ink-muted m-0 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <span>{{ legendLabel }}</span>
            <span v-for="entry in legend" :key="entry.step" class="text-ink-secondary flex items-center gap-1.5">
                <span class="block size-3.5 rounded-[3px]" :style="{ backgroundColor: scaleColor(entry.step) }" aria-hidden="true" />
                {{ entry.label }}
            </span>
        </p>
    </div>
</template>
