<script setup lang="ts">
import { formatNumber } from '@/lib/format';
import { pageLocale } from '@/lib/pageContext';
import { monthName, MONTH_THRESHOLDS, scaleColor, scaleLegend, scaleStep } from '@/lib/statisticsCharts';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * The whole history as years by months, each month a square coloured by how
 * much was finished in it: where a day-by-day map would be years wide, this
 * keeps every year on one row.
 */
const props = defineProps<{
    /** 'YYYY-MM' => count, only months with anything in them. */
    months: Record<string, number>;
    years: number[];
    legendLabel: string;
}>();

const legend = computed(() => scaleLegend(MONTH_THRESHOLDS, (value) => trans('statistics.value_or_more', { value: String(value) })));

const key = (year: number, month: number): string => `${year}-${String(month + 1).padStart(2, '0')}`;
const count = (year: number, month: number): number => props.months[key(year, month)] ?? 0;

/** "March 2019", as the locale writes a month of a year. */
const monthOf = (year: number, month: number): string =>
    new Intl.DateTimeFormat(pageLocale(), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(Date.UTC(year, month, 1));
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <div class="overflow-x-auto pb-1" aria-hidden="true">
            <div class="inline-grid grid-cols-[auto_repeat(12,minmax(2.25rem,4rem))] items-center gap-1">
                <span />
                <span v-for="month in 12" :key="month" class="text-ink-faint text-center text-sm">{{ monthName(month - 1) }}</span>
                <template v-for="year in years" :key="year">
                    <span class="text-ink-faint pr-2 text-sm tabular-nums">{{ year }}</span>
                    <span
                        v-for="month in 12"
                        :key="month"
                        v-tooltip="trans('statistics.chart_value', { label: monthOf(year, month - 1), value: formatNumber(count(year, month - 1)) })"
                        class="block h-[18px] rounded-[3px]"
                        :style="{ backgroundColor: scaleColor(scaleStep(count(year, month - 1), MONTH_THRESHOLDS)) }"
                    />
                </template>
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
