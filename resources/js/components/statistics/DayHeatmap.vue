<script setup lang="ts">
import CalendarGrid from '@/components/statistics/CalendarGrid.vue';
import { formatDayKey, formatNumber } from '@/lib/format';
import { DAY_THRESHOLDS, scaleColor, scaleLegend, scaleStep } from '@/lib/statisticsCharts';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * Every day of a period as a square in a wall-calendar grid, the user's first weekday on top,
 * coloured by how much was finished that day. The steps are fixed counts,
 * not a share of the busiest day, so one day of a bulk entry cannot pale the
 * rest of the year.
 */
defineProps<{
    from: string;
    to: string;
    days: Record<string, number>;
    /** The weekday the user's week opens on (ISO: 1 is Monday). */
    firstWeekday: number;
    /** The legend's caption: "Completed in a day:". */
    legendLabel: string;
}>();

const legend = computed(() => scaleLegend(DAY_THRESHOLDS, (value) => trans('statistics.value_or_more', { value: String(value) })));

const tooltip = (day: string, count: number): string => trans('statistics.chart_value', { label: formatDayKey(day), value: formatNumber(count) });
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <CalendarGrid
            :from="from"
            :to="to"
            :days="days"
            :first-weekday="firstWeekday"
            :color="(_day, count) => scaleColor(scaleStep(count, DAY_THRESHOLDS))"
            :tooltip="tooltip"
        />

        <p class="text-ink-muted m-0 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <span>{{ legendLabel }}</span>
            <span v-for="entry in legend" :key="entry.step" class="text-ink-secondary flex items-center gap-1.5">
                <span class="block size-3.5 rounded-[3px]" :style="{ backgroundColor: scaleColor(entry.step) }" aria-hidden="true" />
                {{ entry.label }}
            </span>
        </p>
    </div>
</template>
