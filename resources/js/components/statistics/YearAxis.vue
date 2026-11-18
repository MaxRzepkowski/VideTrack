<script setup lang="ts">
import { useMediaQuery } from '@/composables/useMediaQuery';
import { formatNumber } from '@/lib/format';
import { statisticsParams, yearWindow } from '@/lib/statistics';
import type { PeriodSpan, YearCount } from '@/types/generated';
import { ChevronLeft, ChevronRight, Infinity as InfinityIcon } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

/**
 * The years of the history side by side, each a small bar of how much it
 * holds, and the whole history after them. The chosen year is lit and says
 * its count; a range of days underlines the years it touches instead, since
 * no single year is chosen then. Every entry is a link, so the period lives
 * in the address and the back button walks through it.
 */
const props = defineProps<{
    years: YearCount[];
    allTime: number;
    period: PeriodSpan;
    type: string | null;
}>();

/** The tallest bar; a year is drawn against the busiest one of the whole history, so paging does not rescale it. */
const BAR_MAX = 40;

const wide = useMediaQuery('(min-width: 80rem)');
const medium = useMediaQuery('(min-width: 48rem)');
const size = computed(() => (wide.value ? 8 : medium.value ? 6 : 4));

const offset = ref(0);
watch(
    () => [props.period.year, props.type],
    () => (offset.value = 0),
);

const visible = computed(() => yearWindow(props.years, props.period.year, size.value, offset.value));
const busiest = computed(() => Math.max(1, ...props.years.map((entry) => entry.completions)));

const barHeight = (count: number): number => Math.max(4, Math.round((count / busiest.value) * BAR_MAX));

const selected = (year: number): boolean => props.period.kind === 'year' && props.period.year === year;

/** A range of days touches the years between its ends. */
const touched = (year: number): boolean =>
    props.period.kind === 'range' &&
    props.period.from !== null &&
    props.period.to !== null &&
    year >= Number(props.period.from.slice(0, 4)) &&
    year <= Number(props.period.to.slice(0, 4));

const href = (period: number | 'all'): string => route('statistics', statisticsParams({ period, type: props.type }));

const entryLabel = (period: string, count: number): string => trans('statistics.year_axis_entry', { period, count: formatNumber(count) });
</script>

<template>
    <nav :aria-label="trans('statistics.year_axis')" class="flex min-w-0 items-end gap-1">
        <button
            type="button"
            :disabled="!visible.before"
            :aria-label="trans('statistics.earlier_years')"
            class="border-line bg-surface text-ink-secondary hover:border-line-strong disabled:text-line-strong mb-[27px] flex size-[42px] shrink-0 items-center justify-center rounded-lg border transition-colors disabled:cursor-default"
            @click="offset++"
        >
            <ChevronLeft class="size-5" aria-hidden="true" />
        </button>

        <Link
            v-for="entry in visible.shown"
            :key="entry.year"
            :href="href(entry.year)"
            preserve-scroll
            :aria-current="selected(entry.year) ? 'page' : undefined"
            :aria-label="entryLabel(String(entry.year), entry.completions)"
            class="group focus-visible:ring-brand flex w-[58px] shrink-0 flex-col items-center gap-1.5 rounded-lg p-2 focus-visible:ring-2 focus-visible:outline-hidden"
            :class="selected(entry.year) ? 'bg-surface-raised' : 'hover:bg-surface'"
        >
            <span
                class="text-sm tabular-nums"
                :class="
                    selected(entry.year) ? 'text-ink-secondary' : 'text-ink-muted opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100'
                "
                aria-hidden="true"
            >
                {{ formatNumber(entry.completions) }}
            </span>
            <span class="flex h-10 items-end" aria-hidden="true">
                <span
                    class="block w-4 rounded-xs"
                    :class="selected(entry.year) ? 'bg-brand' : 'bg-ink-faint'"
                    :style="{ height: `${barHeight(entry.completions)}px` }"
                />
            </span>
            <span
                class="border-b-2 text-base tabular-nums"
                :class="[
                    selected(entry.year) ? 'text-ink font-semibold' : 'text-ink-muted group-hover:text-ink-secondary',
                    touched(entry.year) ? 'border-brand text-ink-secondary' : 'border-transparent',
                ]"
            >
                {{ entry.year }}
            </span>
        </Link>

        <button
            type="button"
            :disabled="!visible.after"
            :aria-label="trans('statistics.later_years')"
            class="border-line bg-surface text-ink-secondary hover:border-line-strong disabled:text-line-strong mb-[27px] flex size-[42px] shrink-0 items-center justify-center rounded-lg border transition-colors disabled:cursor-default"
            @click="offset--"
        >
            <ChevronRight class="size-5" aria-hidden="true" />
        </button>

        <span class="bg-line-strong mx-1 mb-5 h-14 w-px shrink-0" aria-hidden="true" />

        <Link
            :href="href('all')"
            preserve-scroll
            :aria-current="period.kind === 'all' ? 'page' : undefined"
            :aria-label="entryLabel(trans('statistics.whole_history'), allTime)"
            class="group focus-visible:ring-brand flex shrink-0 flex-col items-center gap-1.5 rounded-lg p-2 focus-visible:ring-2 focus-visible:outline-hidden"
            :class="period.kind === 'all' ? 'bg-surface-raised' : 'hover:bg-surface'"
        >
            <span
                class="text-sm tabular-nums"
                :class="
                    period.kind === 'all' ? 'text-ink-secondary' : 'text-ink-muted opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100'
                "
                aria-hidden="true"
            >
                {{ formatNumber(allTime) }}
            </span>
            <span class="flex h-10 items-center" aria-hidden="true">
                <InfinityIcon class="size-7" :class="period.kind === 'all' ? 'text-brand' : 'text-ink-faint'" />
            </span>
            <span
                class="text-base whitespace-nowrap"
                :class="period.kind === 'all' ? 'text-ink font-semibold' : 'text-ink-muted group-hover:text-ink-secondary'"
            >
                {{ trans('statistics.whole_history') }}
            </span>
        </Link>
    </nav>
</template>
