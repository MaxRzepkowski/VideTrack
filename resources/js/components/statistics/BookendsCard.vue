<script setup lang="ts">
import PosterThumb from '@/components/statistics/PosterThumb.vue';
import { leafCode, showTitleOf } from '@/lib/badges';
import { formatNumber, formatTime, localDateKey } from '@/lib/format';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { daysBetween, typeAndYear, weekdayDate } from '@/lib/statistics';
import { mediaUrl } from '@/lib/urls';
import type { Bookend, PeriodSpan } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * The period's first and last finished entry facing each other, with the days
 * between them on the line that joins them.
 */
const props = defineProps<{ first: Bookend; last: Bookend; period: PeriodSpan }>();

const days = computed(() => daysBetween(localDateKey(props.first.at), localDateKey(props.last.at)));

const heading = (which: 'first' | 'last'): string => {
    const year = props.period.kind === 'year' && props.period.year !== null ? String(props.period.year) : null;

    if (which === 'first') {
        return year === null ? trans('statistics.first_completion_of_period') : trans('statistics.first_completion_of_year', { year });
    }

    return year === null ? trans('statistics.last_completion_of_period') : trans('statistics.last_completion_of_year', { year });
};

/** The title it belongs to: an episode's show, anything else itself. */
const titleOf = (bookend: Bookend): string => showTitleOf(bookend.media) ?? bookend.media.title;

/** What was finished within that title: "S04E06", or the type and year ("Movie, 1983"), as every title on the page says it. */
const partOf = (bookend: Bookend): string => {
    const code = leafCode(bookend.media);

    if (code !== null) {
        return code;
    }

    return typeAndYear(bookend.media.type, bookend.media.year);
};

const when = (bookend: Bookend): string =>
    trans('statistics.bookend_when', { date: weekdayDate(bookend.at, props.period.kind !== 'year'), time: formatTime(bookend.at) ?? '' });

const sides = computed(() => [
    { key: 'first', bookend: props.first, heading: heading('first') },
    { key: 'last', bookend: props.last, heading: heading('last') },
]);
</script>

<template>
    <section
        class="border-line-subtle bg-surface grid grid-cols-1 items-center gap-5 rounded-xl border px-6 py-5 md:grid-cols-[1fr_minmax(6rem,34rem)_1fr] md:px-8"
    >
        <template v-for="(side, index) in sides" :key="side.key">
            <div v-if="index === 1" class="hidden items-center gap-2.5 md:flex" aria-hidden="true">
                <span class="bg-line-strong h-px flex-1" />
                <span class="text-ink-faint text-sm whitespace-nowrap">{{
                    transChoice('statistics.count_day', days, { count: formatNumber(days) })
                }}</span>
                <span class="bg-line-strong h-px flex-1" />
            </div>

            <Link
                :href="mediaUrl(side.bookend.media)"
                class="group focus-visible:ring-brand flex min-w-0 items-center gap-4 rounded-md focus-visible:ring-2 focus-visible:outline-hidden"
                :class="index === 1 ? 'md:flex-row-reverse md:text-right' : ''"
            >
                <PosterThumb :path="side.bookend.media.posterPath" size="w185" :width="64" :height="96" class="h-24 w-16 rounded-md" />

                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="text-ink-muted text-sm">{{ side.heading }}</span>
                    <span class="text-ink line-clamp-2 text-base font-semibold group-hover:underline md:line-clamp-1">{{
                        titleOf(side.bookend)
                    }}</span>
                    <span class="text-ink-secondary flex items-center gap-1.5 text-sm" :class="index === 1 ? 'md:justify-end' : ''">
                        <component
                            :is="mediaTypeMeta(side.bookend.media.type).icon"
                            class="size-4 shrink-0"
                            :style="{ color: mediaTypeMeta(side.bookend.media.type).color }"
                            aria-hidden="true"
                        />
                        {{ partOf(side.bookend) }}
                    </span>
                    <span class="text-ink-muted text-sm">{{ when(side.bookend) }}</span>
                </span>
            </Link>
        </template>
    </section>
</template>
