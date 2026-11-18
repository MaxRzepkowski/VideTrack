<script setup lang="ts">
import PosterThumb from '@/components/statistics/PosterThumb.vue';
import Hairline from '@/components/Hairline.vue';
import CalendarGrid from '@/components/statistics/CalendarGrid.vue';
import ColumnChart from '@/components/statistics/ColumnChart.vue';
import RecordCard from '@/components/statistics/RecordCard.vue';
import StatisticsSection from '@/components/statistics/StatisticsSection.vue';
import TypeYear from '@/components/statistics/TypeYear.vue';
import { formatDayKey, formatNumber, formatRuntime } from '@/lib/format';
import { leafType, mediaTypeMeta } from '@/lib/mediaTypes';
import { partRange, rangeLabel } from '@/lib/statistics';
import { weekdayNames } from '@/lib/statisticsCharts';
import { mediaUrl } from '@/lib/urls';
import type { StatisticsRecords } from '@/types/generated';
import { Award, CalendarCheck, CalendarClock, CircleCheck } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * The period's records: the longest run of days, the busiest day with its
 * week and the most of one title in a day. A record the period does not have
 * is left out, not shown empty.
 */
const props = defineProps<{
    records: StatisticsRecords;
}>();

const STREAK = 'var(--color-brand)';
const OTHER_DAY = 'var(--color-line-strong)';
const QUIET_DAY = 'var(--color-line)';

const completed = (count: number): string => transChoice('statistics.count_completed', count, { amount: formatNumber(count) });

const streak = computed(() => props.records.streak);
const streakDays = computed(() => {
    const days: Record<string, number> = {};

    for (const day of streak.value?.otherDays ?? []) {
        days[day] = 1;
    }

    return days;
});

const streakColor = (day: string, count: number): string => {
    const run = streak.value;

    return run !== null && day >= run.from && day <= run.to ? STREAK : count > 0 ? OTHER_DAY : QUIET_DAY;
};

const busiest = computed(() => props.records.busiestDay);
const week = computed(() => busiest.value?.week ?? []);
const weekTicks = computed(() => weekdayNames('short', props.records.firstWeekday));
const weekPeak = computed(() => week.value.findIndex((day) => day.day === busiest.value?.day.day));
const weekCaption = computed(() => {
    const first = week.value[0];
    const last = week.value[week.value.length - 1];

    return first === undefined || last === undefined ? null : trans('statistics.busiest_week', { dates: rangeLabel(first.day, last.day, 'long') });
});

const marathon = computed(() => props.records.marathon);
const marathonParts = computed(() => (marathon.value === null ? '' : mediaTypeMeta(leafType(marathon.value.media.type)).count(marathon.value.parts)));

const cards = computed(() => [streak.value, busiest.value, marathon.value].filter((card) => card !== null).length);
</script>

<template>
    <StatisticsSection
        v-if="cards > 0"
        id="records"
        :icon="Award"
        :title="trans('statistics.records_title')"
        :subtitle="trans('statistics.records_subtitle')"
    >
        <div class="grid grid-cols-1 gap-6" :class="cards === 3 ? 'lg:grid-cols-3' : cards === 2 ? 'md:grid-cols-2' : ''">
            <RecordCard
                v-if="streak"
                :icon="CalendarCheck"
                :title="trans('statistics.streak_title')"
                :record="transChoice('statistics.streak_days', streak.days, { count: formatNumber(streak.days) })"
                :when="rangeLabel(streak.from, streak.to, 'long')"
            >
                <CalendarGrid
                    :from="streak.shownFrom"
                    :to="streak.shownTo"
                    :days="streakDays"
                    :first-weekday="records.firstWeekday"
                    :color="streakColor"
                    :tooltip="(day) => formatDayKey(day)"
                />
                <p class="text-ink-muted m-0 mt-auto flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <span class="flex items-center gap-1.5">
                        <span class="block size-3.5 rounded-[3px]" :style="{ backgroundColor: STREAK }" aria-hidden="true" />
                        {{ trans('statistics.streak_days_of_streak') }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="block size-3.5 rounded-[3px]" :style="{ backgroundColor: OTHER_DAY }" aria-hidden="true" />
                        {{ trans('statistics.streak_other_days') }}
                    </span>
                </p>
            </RecordCard>

            <RecordCard
                v-if="busiest"
                :icon="CircleCheck"
                :title="trans('statistics.busiest_title')"
                :record="completed(busiest.day.count)"
                :when="formatDayKey(busiest.day.day)"
                :caption="weekCaption"
            >
                <ColumnChart
                    :values="week.map((day) => day.count)"
                    :labels="week.map((day) => formatDayKey(day.day))"
                    :tick-labels="weekTicks"
                    color="var(--color-brand)"
                    :highlight="weekPeak < 0 ? null : weekPeak"
                    show-values
                    :height="112"
                    :caption="weekCaption ?? ''"
                />
            </RecordCard>

            <RecordCard
                v-if="marathon"
                :icon="CalendarClock"
                :title="trans('statistics.marathon_title')"
                :record="trans('statistics.marathon_parts', { parts: marathonParts })"
                :when="formatDayKey(marathon.day)"
            >
                <Link
                    :href="mediaUrl(marathon.media)"
                    class="group focus-visible:ring-brand flex gap-4 rounded-lg focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    <PosterThumb
                        :path="marathon.media.posterPath"
                        size="w185"
                        :width="185"
                        :height="278"
                        :widths="[92, 185]"
                        sizes="5.5rem"
                        class="aspect-poster w-22 rounded-lg"
                    />
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="text-ink text-base font-semibold group-hover:underline">{{ marathon.media.title }}</span>
                        <TypeYear :type="marathon.media.type" :year="marathon.media.year" />
                        <span class="text-ink-secondary flex flex-wrap items-center gap-x-2 text-sm">
                            <template v-if="partRange(marathon.first, marathon.last)">
                                <span>{{ partRange(marathon.first, marathon.last) }}</span>
                                <Hairline v-if="marathon.minutes > 0" class="mx-0" />
                            </template>
                            <span v-if="marathon.minutes > 0">{{ formatRuntime(marathon.minutes) }}</span>
                        </span>
                    </span>
                </Link>
            </RecordCard>
        </div>
    </StatisticsSection>
</template>
