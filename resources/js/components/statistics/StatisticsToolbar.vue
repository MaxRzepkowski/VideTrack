<script setup lang="ts">
import PageToolbar from '@/components/PageToolbar.vue';
import RangePanel from '@/components/statistics/RangePanel.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import { adjacentYear, currentQuery, pickableYears, rangeLabel, type StatisticsQuery } from '@/lib/statistics';
import { ICON_BUTTON_CLASS, SELECT_CLASS } from '@/lib/ui';
import type { StatisticsPage } from '@/types/generated';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * The strip that picks what the page counts, sticking under the header like
 * every listing's: the period on the left (a step to the year before or
 * after, a year, or in the panel beside it any range of days or the whole
 * history) and the type on the right. A period the panel picked shows in the
 * year list as an entry that cannot be picked there.
 *
 * On a phone the strip keeps one row, so it drops what has a stand-in: the
 * year list does the arrows' job, and the range button keeps only its icon.
 */
const props = defineProps<{ page: StatisticsPage }>();

const emit = defineEmits<{ visit: [query: StatisticsQuery] }>();

/** What stands for a range of days and for the whole history in the year list: both are picked in the panel beside it. */
const RANGE = 'range' as const;
const ALL = 'all' as const;

const years = computed(() => pickableYears(props.page.years, props.page.period));
const older = computed(() => adjacentYear(props.page.years, props.page.period, 'older'));
const newer = computed(() => adjacentYear(props.page.years, props.page.period, 'newer'));

const range = computed(() =>
    props.page.period.kind === 'range' && props.page.period.from !== null && props.page.period.to !== null
        ? { from: props.page.period.from, to: props.page.period.to }
        : null,
);

const period = computed({
    get: () => (props.page.period.kind === 'year' ? String(props.page.period.year) : props.page.period.kind === 'all' ? ALL : RANGE),
    set: (value: string) => {
        if (value !== RANGE && value !== ALL) {
            emit('visit', { period: Number(value), type: props.page.type });
        }
    },
});

const type = computed({
    get: () => props.page.type,
    set: (value: string | null) => emit('visit', { ...currentQuery(props.page.period, props.page.type), type: value }),
});
</script>

<template>
    <PageToolbar variant="band">
        <template #search>
            <div class="flex items-center gap-2">
                <button
                    v-tooltip="trans('statistics.previous_year')"
                    type="button"
                    data-toolbar-control
                    :class="[ICON_BUTTON_CLASS, 'max-md:hidden']"
                    :disabled="older === null"
                    :aria-label="trans('statistics.previous_year')"
                    @click="older !== null && emit('visit', { period: older, type: page.type })"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" />
                </button>
                <select v-model="period" data-toolbar-control :class="SELECT_CLASS" :aria-label="trans('statistics.period')">
                    <option v-for="year in years" :key="year" :value="String(year)">
                        {{ year }}
                    </option>
                    <option v-if="page.period.kind === 'all'" :value="ALL" disabled>{{ trans('statistics.whole_history') }}</option>
                    <option v-if="range" :value="RANGE" disabled>{{ rangeLabel(range.from, range.to) }}</option>
                </select>
                <button
                    v-tooltip="trans('statistics.next_year')"
                    type="button"
                    data-toolbar-control
                    :class="[ICON_BUTTON_CLASS, 'max-md:hidden']"
                    :disabled="newer === null"
                    :aria-label="trans('statistics.next_year')"
                    @click="newer !== null && emit('visit', { period: newer, type: page.type })"
                >
                    <ChevronRight class="size-4" aria-hidden="true" />
                </button>
            </div>

            <RangePanel
                :from="range?.from ?? null"
                :to="range?.to ?? null"
                :earliest="page.earliest"
                :whole="page.period.kind === 'all'"
                @apply="(from, to) => emit('visit', { from, to, type: page.type })"
                @whole="emit('visit', { period: ALL, type: page.type })"
            />
        </template>

        <template #view>
            <TypeFilter v-model="type" :options="page.types" />
        </template>
    </PageToolbar>
</template>
