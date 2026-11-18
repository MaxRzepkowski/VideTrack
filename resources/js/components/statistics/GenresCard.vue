<script setup lang="ts">
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import ChartCard from '@/components/statistics/ChartCard.vue';
import { formatRuntime, formatWatchTime } from '@/lib/format';
import { RATING_MAX } from '@/lib/ratings';
import { formatUnitAmount, type ReadUnit } from '@/lib/progress';
import { countTitles, formatRating, GENRE_RATINGS_MIN, rankGenres, type GenreMeasure } from '@/lib/statistics';
import { SELECT_CLASS_ON_CARD } from '@/lib/ui';
import type { GenreStat } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

/**
 * The genres of the period's titles, ranked by a measure the user picks:
 * how many titles, how much time (or how much was read), or how they were
 * rated. Ten at first; the rest behind the shared "show more" pill.
 */
const props = defineProps<{
    genres: GenreStat[];
    /** The unit the amounts are read in, for one type that is read; null when they are time. */
    readIn: ReadUnit | null;
    type: string | null;
    /** The line under the title: period and scope. */
    subtitle: string;
    /** Whether the time includes reading, estimated at the user's pace. */
    readingEstimated: boolean;
}>();

const SHOWN = 10;

const measure = ref<GenreMeasure>('titles');
const expanded = ref(false);

const ranked = computed(() => rankGenres(props.genres, measure.value));
const shown = computed(() => (expanded.value ? ranked.value : ranked.value.slice(0, SHOWN)));
const top = computed(() => Math.max(...ranked.value.map((entry) => entry.value), measure.value === 'rating' ? RATING_MAX : 1));

const title = computed(() => {
    if (measure.value === 'titles') {
        return trans('statistics.genres_by_titles');
    }

    if (measure.value === 'rating') {
        return trans('statistics.genres_by_rating');
    }

    return props.readIn === null ? trans('statistics.genres_by_amount') : trans('statistics.genres_by_read');
});

const options = computed(() => [
    { value: 'titles', label: trans('statistics.measure_titles') },
    { value: 'amount', label: props.readIn === null ? trans('statistics.measure_amount') : trans('statistics.measure_read') },
    { value: 'rating', label: trans('statistics.measure_rating') },
]);

const titles = (count: number): string => countTitles(props.type, count);

const amount = (genre: GenreStat): string =>
    props.readIn === null ? (formatRuntime(genre.amount) ?? formatWatchTime(0)) : formatUnitAmount(props.readIn, genre.amount);

/** A row says only the measure it is ranked by, so the column reads as one scale. */
const value = (genre: GenreStat): string => {
    if (measure.value === 'titles') {
        return titles(genre.titles);
    }

    return measure.value === 'rating' && genre.averageRating !== null ? formatRating(genre.averageRating) : amount(genre);
};
</script>

<template>
    <ChartCard :title="title" :subtitle="subtitle">
        <template #actions>
            <label class="flex items-center gap-2.5">
                <span class="text-ink-faint text-base font-semibold">{{ trans('statistics.measure') }}</span>
                <select v-model="measure" :class="SELECT_CLASS_ON_CARD">
                    <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </label>
        </template>

        <ul class="m-0 grid list-none grid-cols-[minmax(6rem,11rem)_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2.5 p-0">
            <li v-for="entry in shown" :key="entry.genre.name" class="contents">
                <span class="text-ink-secondary truncate text-base">{{ entry.genre.name }}</span>
                <span class="bg-brand block h-3 rounded-[2px]" :style="{ width: `${(entry.value / top) * 100}%` }" aria-hidden="true" />
                <span class="text-ink text-right text-base font-semibold whitespace-nowrap tabular-nums">{{ value(entry.genre) }}</span>
            </li>
        </ul>

        <p v-if="measure === 'amount' && readIn === null && readingEstimated" class="text-ink-faint m-0 text-sm">
            {{ trans('statistics.reading_estimated_note') }}
        </p>

        <p v-if="measure === 'rating'" class="text-ink-faint m-0 text-sm">
            {{ trans('statistics.genres_rating_note', { count: String(GENRE_RATINGS_MIN) }) }}
        </p>

        <ShowMoreToggle v-if="ranked.length > SHOWN" v-model="expanded" :hidden-count="ranked.length - SHOWN" class="self-start" />
    </ChartCard>
</template>
