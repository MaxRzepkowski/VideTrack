<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import { durationTerms, formatNumber, formatTotalRuntime } from '@/lib/format';
import { mediaTypeMeta, readUnitOf } from '@/lib/mediaTypes';
import { RATING_MIN } from '@/lib/ratings';
import {
    activeEvery,
    commonestRating,
    communityComparison,
    formatPercent,
    formatRating,
    periodDates,
    periodTitle,
    timeRows,
    titleMarks,
} from '@/lib/statistics';
import type { PeriodSpan, PeriodSummary, PeriodTitles, WatchStats } from '@/types/generated';
import { statCounts } from '@/lib/progress';
import { CalendarCheck, Flag, LibraryBig, Star } from '@lucide/vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * The period's summary, always on top. One figure leads: the time the period
 * took, marked as an estimate when reading went into it, with each type's
 * share of it beneath. Beside it, three sums each drawn small: what the
 * period's titles were marked as, how many of its days were active, and how
 * they were rated. Marks and ratings both count titles, a show once however
 * many of its episodes, so the two read side by side.
 */
const props = defineProps<{
    period: PeriodSpan;
    summary: PeriodSummary;
    titles: PeriodTitles;
    type: string | null;
    /** One type's view: its totals, said beside the time; null for every type, whose tiles say them. */
    stats: WatchStats | null;
}>();

const typeLabel = computed(() => (props.type === null ? trans('statistics.all_types') : mediaTypeMeta(props.type).plural()));
const dates = computed(() => periodDates(props.period));

const estimated = computed(() => props.summary.readingMinutes >= 1);
const hero = computed(() => durationTerms(props.summary.time.total));

const minutesPer = (days: number): string | null => {
    const minutes = days > 0 ? Math.round(props.summary.time.total / days) : 0;

    return minutes < 1 ? null : (formatTotalRuntime(minutes) ?? null);
};

const daily = computed(() => {
    const time = minutesPer(props.period.days);

    return time === null ? null : trans('statistics.hero_daily', { time });
});

/** Under the time: one type's totals first, then the daily average and, if reading went in, that it is estimated. */
const captions = computed(() => [
    ...(props.stats === null || props.type === null ? [] : statCounts(props.type, props.stats).map((text) => ({ text, strong: true }))),
    ...(daily.value === null ? [] : [{ text: daily.value, strong: false }]),
    ...(estimated.value ? [{ text: trans('statistics.hero_reading_estimated'), strong: false }] : []),
]);

const rows = computed(() =>
    timeRows(props.summary.time).map((row) => {
        const time = formatTotalRuntime(row.minutes) ?? '';

        return { ...row, time: readUnitOf(row.type) !== null ? trans('statistics.estimated_value', { value: time }) : time };
    }),
);

const marks = computed(() => titleMarks(props.titles).filter((entry) => entry.count > 0));

const activeShare = computed(() =>
    transChoice('statistics.active_of_days', props.period.days, {
        count: formatNumber(props.period.days),
        percent: formatPercent(props.summary.activeDays, props.period.days),
    }),
);

const activeNotes = computed(() => {
    const every = activeEvery(props.period.days, props.summary.activeDays);
    const perDay = minutesPer(props.summary.activeDays);

    return [
        dates.value,
        every === null ? null : transChoice('statistics.active_every', every, { count: formatNumber(every) }),
        perDay === null ? null : trans('statistics.per_active_day', { time: perDay }),
    ].filter((note): note is string => note !== null);
});

const activeWidth = computed(() => (props.period.days === 0 ? 0 : Math.min(100, (props.summary.activeDays / props.period.days) * 100)));

const ratingPeak = computed(() => Math.max(1, ...props.summary.ratingCounts));
const commonest = computed(() => commonestRating(props.summary.ratingCounts, RATING_MIN));
const ratingTop = computed(() => RATING_MIN + props.summary.ratingCounts.length - 1);

/** A rating's bar, against the most given one; a rating given at all keeps a sliver. */
const ratingHeight = (count: number): string => `${count === 0 ? 0 : Math.max(4, (count / ratingPeak.value) * 100)}%`;
</script>

<template>
    <section
        class="border-line-subtle bg-surface grid grid-cols-1 overflow-hidden rounded-xl border"
        :class="type === null ? 'lg:grid-cols-[minmax(0,1fr)_22.5rem]' : ''"
    >
        <div class="flex min-w-0 flex-col gap-6 px-6 py-7 md:px-10 md:py-8">
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                <h2 class="text-ink m-0 text-[1.875rem] leading-9 font-bold">{{ periodTitle(period) }}</h2>
                <p class="border-line text-ink-secondary m-0 flex h-9 items-center gap-2 rounded-lg border px-3 text-base font-semibold">
                    <component
                        :is="type === null ? LibraryBig : mediaTypeMeta(type).icon"
                        class="size-[18px] shrink-0"
                        :class="type === null ? 'text-brand-soft' : ''"
                        :style="{ color: type === null ? undefined : mediaTypeMeta(type).color }"
                        aria-hidden="true"
                    />
                    {{ typeLabel }}
                </p>
            </div>

            <div class="flex flex-col gap-1.5">
                <p class="text-ink-muted m-0 text-base">{{ trans('statistics.hero_label') }}</p>
                <p class="text-ink m-0 flex flex-wrap items-baseline gap-x-2.5 gap-y-1 leading-none">
                    <span v-if="estimated" class="text-ink-muted text-[2.5rem] font-semibold">{{ trans('common.estimate_sign') }}</span>
                    <span
                        v-for="(term, index) in hero"
                        :key="term.unit"
                        class="inline-flex items-baseline gap-x-2.5"
                        :class="index > 0 ? 'ml-2' : ''"
                    >
                        <span class="text-[4rem] font-bold tracking-tight">{{ term.value }}</span>
                        <span class="text-ink-secondary text-[1.375rem]">{{ term.unit }}</span>
                    </span>
                </p>
                <p v-if="captions.length > 0" class="text-ink-muted m-0 mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-base">
                    <template v-for="(caption, index) in captions" :key="caption.text">
                        <Hairline v-if="index > 0" class="mx-0" />
                        <span :class="caption.strong ? 'text-ink-secondary font-semibold' : ''">{{ caption.text }}</span>
                    </template>
                </p>
            </div>

            <ul v-if="rows.length > 1" class="m-0 flex list-none flex-col gap-3 p-0">
                <li
                    v-for="row in rows"
                    :key="row.type"
                    class="grid grid-cols-[minmax(0,7.5rem)_minmax(0,1fr)_auto] items-center gap-x-4 text-base sm:grid-cols-[7.5rem_minmax(0,1fr)_9rem_3.75rem]"
                >
                    <span class="text-ink-secondary flex min-w-0 items-center gap-2">
                        <component
                            :is="mediaTypeMeta(row.type).icon"
                            class="size-[18px] shrink-0"
                            :style="{ color: mediaTypeMeta(row.type).color }"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ mediaTypeMeta(row.type).plural() }}</span>
                    </span>
                    <span class="bg-line h-2.5 overflow-hidden rounded-[5px]" aria-hidden="true">
                        <span
                            class="block h-full rounded-[5px]"
                            :style="{ width: `${row.width}%`, backgroundColor: mediaTypeMeta(row.type).color }"
                        />
                    </span>
                    <span class="text-ink text-right font-semibold whitespace-nowrap tabular-nums">{{ row.time }}</span>
                    <span class="text-ink-muted hidden text-right tabular-nums sm:block">{{ row.share }}</span>
                </li>
            </ul>
        </div>

        <!-- Every type: a column beside the time, its rows split by time. One type has no rows of types, so the
             three sums run along the bottom instead, as three columns. -->
        <div
            class="border-line-subtle divide-line-subtle grid grid-cols-1 border-t"
            :class="type === null ? 'divide-y lg:border-t-0 lg:border-l' : 'divide-y md:grid-cols-3 md:divide-x md:divide-y-0'"
        >
            <div class="flex flex-col gap-2.5 px-6 py-6 md:px-7">
                <p class="text-ink-muted m-0 flex items-center gap-2 text-base">
                    <Flag class="text-brand-soft size-[18px] shrink-0" aria-hidden="true" />
                    {{ trans('statistics.marked_label') }}
                </p>
                <p class="text-ink m-0 text-[2rem] leading-9 font-bold">{{ formatNumber(titles.titles.total) }}</p>
                <div v-if="marks.length > 0" class="bg-line flex h-2 gap-0.5 overflow-hidden rounded" aria-hidden="true">
                    <span
                        v-for="entry in marks"
                        :key="entry.mark"
                        class="h-full min-w-1"
                        :style="{ flexGrow: entry.count, backgroundColor: entry.color }"
                    />
                </div>
                <p class="text-ink-muted m-0 flex flex-wrap gap-x-3.5 gap-y-1 text-sm">
                    <span v-for="entry in marks" :key="entry.mark" class="flex items-center gap-1.5">
                        <span class="h-1.5 w-3 shrink-0 rounded-[2px]" :style="{ backgroundColor: entry.color }" aria-hidden="true" />
                        {{ entry.counted }}
                    </span>
                    <span v-if="marks.length === 0">{{ trans('statistics.nothing_marked') }}</span>
                </p>
            </div>

            <div class="flex flex-col gap-2.5 px-6 py-6 md:px-7">
                <p class="text-ink-muted m-0 flex items-center gap-2 text-base">
                    <CalendarCheck class="text-brand-soft size-[18px] shrink-0" aria-hidden="true" />
                    {{ trans('statistics.active_days') }}
                </p>
                <p class="m-0 flex flex-wrap items-baseline gap-x-2.5">
                    <span class="text-ink text-[2rem] leading-9 font-bold">{{ formatNumber(summary.activeDays) }}</span>
                    <span class="text-ink-muted text-base">{{ activeShare }}</span>
                </p>
                <div class="bg-line h-2 overflow-hidden rounded" aria-hidden="true">
                    <div class="bg-ink-secondary h-full rounded" :style="{ width: `${activeWidth}%` }" />
                </div>
                <p v-if="activeNotes.length > 0" class="text-ink-muted m-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                    <template v-for="(note, index) in activeNotes" :key="note">
                        <Hairline v-if="index > 0" class="mx-0" />
                        <span>{{ note }}</span>
                    </template>
                </p>
            </div>

            <div class="flex flex-col gap-2.5 px-6 py-6 md:px-7">
                <p class="text-ink-muted m-0 flex items-center gap-2 text-base">
                    <Star class="text-rating-gold size-[18px] shrink-0" aria-hidden="true" />
                    {{ trans('statistics.ratings_label') }}
                    <Hairline class="mx-0" />
                    <span class="tabular-nums">{{ formatNumber(summary.ratings) }}</span>
                </p>
                <p v-if="summary.averageRating === null" class="text-ink-muted m-0 text-base">{{ trans('statistics.none_rated') }}</p>
                <template v-else>
                    <p class="m-0 flex flex-wrap items-baseline gap-x-2.5">
                        <span class="text-ink text-[2rem] leading-9 font-bold">{{ formatRating(summary.averageRating) }}</span>
                        <span class="text-ink-muted text-base">{{ trans('statistics.average_label') }}</span>
                        <span v-if="summary.communityDelta !== null" class="text-ink-muted text-sm">{{
                            communityComparison(summary.communityDelta)
                        }}</span>
                    </p>
                    <div class="flex h-12 items-end gap-1">
                        <span
                            v-for="(count, index) in summary.ratingCounts"
                            :key="index"
                            v-tooltip="trans('statistics.rating_bar', { rating: String(index + RATING_MIN), count: formatNumber(count) })"
                            class="flex h-full flex-1 items-end"
                        >
                            <span
                                class="block w-full rounded-xs"
                                :class="index + RATING_MIN === commonest ? 'bg-rating-gold' : 'bg-chart-line'"
                                :style="{ height: ratingHeight(count) }"
                            />
                        </span>
                    </div>
                    <p class="text-ink-faint m-0 flex justify-between text-sm tabular-nums">
                        <span>{{ RATING_MIN }}</span>
                        <span v-if="commonest !== null">{{ trans('statistics.most_common_rating', { rating: String(commonest) }) }}</span>
                        <span>{{ ratingTop }}</span>
                    </p>
                </template>
            </div>
        </div>
    </section>
</template>
