<script setup lang="ts">
import RangePanel from '@/components/statistics/RangePanel.vue';
import YearAxis from '@/components/statistics/YearAxis.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import { currentQuery, rangeLabel, statisticsParams, type StatisticsQuery } from '@/lib/statistics';
import type { StatisticsPage } from '@/types/generated';
import { CalendarClock, X } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * The strip that picks what the page counts: the year axis on the left, a
 * range of days and the type on the right. A picked range shows as a chip
 * whose cross returns to the current year.
 */
const props = defineProps<{ page: StatisticsPage }>();

const emit = defineEmits<{ visit: [query: StatisticsQuery] }>();

const type = computed({
    get: () => props.page.type,
    set: (value: string | null) => emit('visit', { ...currentQuery(props.page.period, props.page.type), type: value }),
});

const range = computed(() =>
    props.page.period.kind === 'range' && props.page.period.from !== null && props.page.period.to !== null
        ? { from: props.page.period.from, to: props.page.period.to }
        : null,
);

const currentYearHref = computed(() => route('statistics', statisticsParams({ type: props.page.type })));
</script>

<template>
    <div class="bg-surface-band border-line-faint w-full border-b">
        <div class="max-w-content mx-auto flex w-full flex-wrap items-end justify-between gap-x-8 gap-y-3 px-4 py-3 md:px-10">
            <YearAxis :years="page.years" :all-time="page.allTime" :period="page.period" :type="page.type" />

            <div class="flex flex-wrap items-center gap-3 pb-1.5">
                <div v-if="range" class="bg-brand-strong text-on-accent flex h-10 items-center gap-2 rounded-lg pr-1.5 pl-3 text-base font-semibold">
                    <CalendarClock class="size-4" aria-hidden="true" />
                    <span>{{ rangeLabel(range.from, range.to) }}</span>
                    <Link
                        :href="currentYearHref"
                        preserve-scroll
                        :aria-label="trans('statistics.back_to_current_year')"
                        class="hover:bg-brand focus-visible:ring-on-accent flex size-7 items-center justify-center rounded-md focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </Link>
                </div>

                <RangePanel
                    :from="range?.from ?? null"
                    :to="range?.to ?? null"
                    :earliest="page.earliest"
                    @apply="(from, to) => emit('visit', { from, to, type: page.type })"
                />

                <TypeFilter v-model="type" :options="page.types" />
            </div>
        </div>
    </div>
</template>
