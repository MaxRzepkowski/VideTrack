<script setup lang="ts">
import ChartTable from '@/components/statistics/ChartTable.vue';
import { formatNumber } from '@/lib/format';
import { monthName, monthOfYear, MONTH_THRESHOLDS, scaleColor, scaleLegend, scaleStep } from '@/lib/statisticsCharts';
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

/** "March 2019". */
const monthOf = (year: number, month: number): string => monthOfYear(key(year, month));
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <div class="overflow-x-auto pb-1" aria-hidden="true">
            <!-- Twelve columns that narrow to the card: on a phone a month is a letter, its name in the tooltip. -->
            <div class="inline-grid w-full max-w-max grid-cols-[auto_repeat(12,minmax(1rem,4rem))] items-center gap-1">
                <span />
                <span v-for="month in 12" :key="month" class="text-ink-faint text-center text-sm">
                    <span class="sm:hidden">{{ monthName(month - 1, 'narrow') }}</span>
                    <span class="max-sm:hidden">{{ monthName(month - 1) }}</span>
                </span>
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

        <ChartTable>
            <thead>
                <tr>
                    <td />
                    <th v-for="month in 12" :key="month" scope="col">{{ monthName(month - 1, 'long') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="year in years" :key="year">
                    <th scope="row">{{ year }}</th>
                    <td v-for="month in 12" :key="month">{{ formatNumber(count(year, month - 1)) }}</td>
                </tr>
            </tbody>
        </ChartTable>

        <p class="text-ink-muted m-0 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <span>{{ legendLabel }}</span>
            <span v-for="entry in legend" :key="entry.step" class="text-ink-secondary flex items-center gap-1.5">
                <span class="block size-3.5 rounded-[3px]" :style="{ backgroundColor: scaleColor(entry.step) }" aria-hidden="true" />
                {{ entry.label }}
            </span>
        </p>
    </div>
</template>
