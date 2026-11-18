<script setup lang="ts">
import BarList from '@/components/statistics/BarList.vue';
import ChartCard from '@/components/statistics/ChartCard.vue';
import ColumnChart from '@/components/statistics/ColumnChart.vue';
import DayHeatmap from '@/components/statistics/DayHeatmap.vue';
import MonthGrid from '@/components/statistics/MonthGrid.vue';
import StatisticsSection from '@/components/statistics/StatisticsSection.vue';
import { formatCalendarDate, formatHourOfDay, formatNumber } from '@/lib/format';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { periodTitle } from '@/lib/statistics';
import { averages, bucketName, bucketTick, tickEvery } from '@/lib/statisticsActivity';
import { fromWeekday, peakIndex, weekdayNames } from '@/lib/statisticsCharts';
import type { ActivityBucket, PeriodSpan, StatisticsActivity } from '@/types/generated';
import { CalendarClock } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * When things were finished: per month (per year over a long period) for
 * each type, day by day, by weekday and by hour. Every chart says its period,
 * its scope and its headline fact above it.
 */
const props = defineProps<{
    activity: StatisticsActivity;
    period: PeriodSpan;
    /** The chosen type; null for every type. */
    type: string | null;
}>();

const BRAND = 'var(--color-brand)';
const color = computed(() => (props.type === null ? BRAND : mediaTypeMeta(props.type).color));
const highlight = computed(() => (props.type === null ? 'var(--color-brand-soft)' : 'var(--color-ink-secondary)'));

const periodName = computed(() => periodTitle(props.period));
const scope = computed(() => (props.type === null ? trans('statistics.all_types_together') : mediaTypeMeta(props.type).plural().toLocaleLowerCase()));
const ticks = computed(() => props.activity.buckets.map((bucket) => bucketTick(bucket, props.activity.bucket)));
const names = computed(() => props.activity.buckets.map((bucket) => bucketName(bucket, props.activity.bucket)));

/** The chart's words for what a column holds: a day, a week, a month or a year. */
const PER_BUCKET: Record<ActivityBucket, { every: string; one: string; scales: string }> = {
    day: { every: 'statistics.per_day_by_type', one: 'statistics.per_day_of_type', scales: 'statistics.per_day_by_type_subtitle' },
    week: { every: 'statistics.per_week_by_type', one: 'statistics.per_week_of_type', scales: 'statistics.per_week_by_type_subtitle' },
    month: { every: 'statistics.per_month_by_type', one: 'statistics.per_month_of_type', scales: 'statistics.per_month_by_type_subtitle' },
    year: { every: 'statistics.per_year_by_type', one: 'statistics.per_year_of_type', scales: 'statistics.per_year_by_type_subtitle' },
};

const words = computed(() => PER_BUCKET[props.activity.bucket]);

/** The types with anything finished: what the time was spent on. */
const shownTypes = computed(() => props.activity.series.filter((series) => series.counts.some((count) => count > 0)).map((series) => series.type));
const paces = computed(() => averages(props.activity, props.period.days, shownTypes.value));

/** One type's view: its chart says its busiest bucket. */
const single = computed(() => (props.type === null ? null : (props.activity.series[0] ?? null)));
const singlePeak = computed(() => (single.value === null ? null : peakIndex(single.value.counts)));

const perBucketTitle = computed(() => {
    return props.type === null ? trans(words.value.every) : trans(words.value.one, { type: mediaTypeMeta(props.type).plural() });
});

const perBucketSubtitle = computed(() => {
    if (props.type === null) {
        return trans(words.value.scales, { period: periodName.value });
    }

    const peak = singlePeak.value;

    return peak === null || single.value === null
        ? trans('statistics.period_sentence', { period: periodName.value })
        : trans('statistics.peak_subtitle', {
              period: periodName.value,
              scope: scope.value,
              peak: names.value[peak] ?? '',
              count: formatNumber(single.value.counts[peak] ?? 0),
          });
});

const activeDays = computed(() => Object.keys(props.activity.days ?? {}).length);

const daysSubtitle = computed(() => {
    const busiest = props.activity.busiestDay;
    const common = { period: periodName.value, scope: scope.value, active: formatNumber(activeDays.value), days: formatNumber(props.period.days) };

    return busiest === null
        ? trans('statistics.days_subtitle_quiet', common)
        : trans('statistics.days_subtitle', { ...common, date: formatCalendarDate(busiest.day) ?? '', count: formatNumber(busiest.count) });
});

const gridYears = computed(() => {
    const months = Object.keys(props.activity.months ?? {});
    const years = months.map((month) => Number(month.slice(0, 4)));

    if (years.length === 0) {
        return [];
    }

    const first = Math.min(...years);
    const last = Math.max(...years);

    return Array.from({ length: last - first + 1 }, (_, index) => first + index);
});

/** The weekdays from the one the user's week opens on. */
const weekdayCounts = computed(() => fromWeekday(props.activity.weekdays, props.activity.firstWeekday));

const weekdayRows = computed(() => {
    const labels = weekdayNames('long', props.activity.firstWeekday);

    return weekdayCounts.value.map((value, index) => ({ key: String(index), label: labels[index] ?? '', value }));
});

const weekdayPeak = computed(() => peakIndex(weekdayCounts.value));
const hourPeak = computed(() => peakIndex(props.activity.hours));
const hourLabels = Array.from({ length: 24 }, (_, hour) => formatHourOfDay(hour));

const weekdaySubtitle = computed(() =>
    weekdayPeak.value === null
        ? trans('statistics.period_sentence', { period: periodName.value })
        : trans('statistics.peak_subtitle', {
              period: periodName.value,
              scope: scope.value,
              peak: weekdayRows.value[weekdayPeak.value]?.label ?? '',
              count: formatNumber(weekdayCounts.value[weekdayPeak.value] ?? 0),
          }),
);

const hourSubtitle = computed(() =>
    hourPeak.value === null
        ? trans('statistics.period_sentence', { period: periodName.value })
        : trans('statistics.hours_subtitle', {
              period: periodName.value,
              scope: scope.value,
              hour: hourLabels[hourPeak.value] ?? '',
              count: formatNumber(props.activity.hours[hourPeak.value] ?? 0),
          }),
);
</script>

<template>
    <StatisticsSection
        id="activity"
        :icon="CalendarClock"
        :title="trans('statistics.activity_title')"
        :subtitle="trans('statistics.activity_subtitle')"
    >
        <ChartCard :title="perBucketTitle" :subtitle="perBucketSubtitle">
            <dl v-if="paces.length > 0" class="border-line-subtle m-0 grid grid-cols-1 gap-4 border-y py-3.5 sm:grid-cols-3">
                <div
                    v-for="(pace, index) in paces"
                    :key="pace.key"
                    class="flex flex-col gap-0.5"
                    :class="index > 0 ? 'sm:border-line-subtle sm:border-l sm:pl-6' : ''"
                >
                    <dt class="text-ink-muted text-sm">{{ pace.label }}</dt>
                    <dd class="text-ink m-0 text-lg font-semibold">{{ pace.value }}</dd>
                    <dd v-if="pace.time" class="text-ink-secondary m-0 text-sm">{{ pace.time }}</dd>
                </div>
            </dl>

            <div v-if="single" class="pt-6">
                <ColumnChart
                    :values="single.counts"
                    :labels="names"
                    :ticks="tickEvery(ticks.length, 12)"
                    :tick-labels="ticks"
                    :color="color"
                    :highlight="null"
                    show-values
                    :height="180"
                    :caption="perBucketTitle"
                />
            </div>

            <div v-else class="grid grid-cols-1 gap-x-10 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="series in activity.series" :key="series.type" class="flex min-w-0 flex-col gap-3">
                    <p class="m-0 flex items-center gap-2 text-base font-semibold">
                        <component
                            :is="mediaTypeMeta(series.type).icon"
                            class="size-5 shrink-0"
                            :style="{ color: mediaTypeMeta(series.type).color }"
                            aria-hidden="true"
                        />
                        <span class="text-ink min-w-0 flex-1 truncate">{{ mediaTypeMeta(series.type).plural() }}</span>
                        <span class="text-ink-secondary tabular-nums">{{ formatNumber(series.counts.reduce((sum, count) => sum + count, 0)) }}</span>
                    </p>
                    <ColumnChart
                        :values="series.counts"
                        :labels="names"
                        :ticks="tickEvery(ticks.length, 4)"
                        :tick-labels="ticks"
                        :color="mediaTypeMeta(series.type).color"
                        axis
                        :height="96"
                        :caption="trans('statistics.chart_of_type', { type: mediaTypeMeta(series.type).plural(), chart: perBucketTitle })"
                    />
                </div>
            </div>
        </ChartCard>

        <ChartCard
            v-if="activity.days !== null && period.from !== null && period.to !== null"
            :title="type === null ? trans('statistics.each_day') : trans('statistics.each_day_of_type', { type: mediaTypeMeta(type).plural() })"
            :subtitle="daysSubtitle"
        >
            <DayHeatmap
                :from="period.from"
                :to="period.to"
                :days="activity.days"
                :first-weekday="activity.firstWeekday"
                :legend-label="trans('statistics.completed_in_a_day')"
            />
        </ChartCard>

        <ChartCard
            v-else-if="activity.months !== null"
            :title="trans('statistics.each_month_of_history')"
            :subtitle="trans('statistics.months_subtitle', { period: periodName, scope })"
        >
            <MonthGrid :months="activity.months" :years="gridYears" :legend-label="trans('statistics.completed_in_a_month')" />
        </ChartCard>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
            <ChartCard :title="trans('statistics.by_weekday')" :subtitle="weekdaySubtitle">
                <BarList :rows="weekdayRows" :color="color" :highlight-color="highlight" />
                <p class="text-ink-faint m-0 text-sm">{{ trans('statistics.weekday_note') }}</p>
            </ChartCard>

            <ChartCard :title="trans('statistics.by_hour')" :subtitle="hourSubtitle">
                <ColumnChart
                    :values="activity.hours"
                    :labels="hourLabels"
                    :ticks="(hour) => hour % 3 === 0"
                    :color="color"
                    :highlight="hourPeak"
                    :highlight-color="highlight"
                    axis
                    :height="150"
                    :caption="trans('statistics.by_hour')"
                />
            </ChartCard>
        </div>
    </StatisticsSection>
</template>
