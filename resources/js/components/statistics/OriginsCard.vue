<script setup lang="ts">
import ChartCard from '@/components/statistics/ChartCard.vue';
import WorldMap from '@/components/statistics/WorldMap.vue';
import { countryName, formatNumber, languageName } from '@/lib/format';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { COUNTRY_THRESHOLDS, MAP_GROUND } from '@/lib/origins';
import { scaleColor, scaleLegend } from '@/lib/statisticsCharts';
import type { OriginTally, StatisticsOrigins } from '@/types/generated';
import { countTitles, typeNoun } from '@/lib/statistics';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * Where the period's titles come from: the map of their countries, and
 * under it the countries and original languages most of them share.
 */
const props = defineProps<{
    origins: StatisticsOrigins;
    type: string | null;
    /** The line under the title: period and scope. */
    subtitle: string;
}>();

/** Rows in each list beside the map; the map shows every country. */
const LISTED = 8;

const title = computed(() => trans('statistics.origins_title', { type: typeNoun(props.type) }));

const counts = computed(() => Object.fromEntries(props.origins.countries.map((country) => [country.code, country.titles])));

const titles = (count: number): string => countTitles(props.type, count);

const describeCountry = (code: string, count: number): string =>
    trans('statistics.origins_tooltip', { name: countryName(code), titles: titles(count) });

/** One type's own colour for its bars; the brand where several types share a list. */
const barColor = (types: string[]): string => (types.length === 1 && types[0] !== undefined ? mediaTypeMeta(types[0]).color : 'var(--color-brand)');

const lists = computed(() =>
    [
        {
            key: 'countries',
            label: trans('statistics.origins_countries'),
            tallies: props.origins.countries,
            types: props.origins.countryTypes,
            name: countryName,
        },
        {
            key: 'languages',
            label: trans('statistics.origins_languages'),
            tallies: props.origins.languages,
            types: props.origins.languageTypes,
            name: languageName,
        },
    ]
        .filter((list) => list.tallies.length > 0)
        .map((list) => ({
            ...list,
            color: barColor(list.types),
            top: Math.max(1, list.tallies[0]?.titles ?? 1),
            rows: list.tallies
                .slice(0, LISTED)
                .map((tally: OriginTally) => ({ code: tally.code, name: list.name(tally.code), titles: tally.titles })),
        })),
);

const legend = computed(() =>
    scaleLegend(COUNTRY_THRESHOLDS, (value) => trans('statistics.value_or_more', { value: formatNumber(value) })).map((entry) => ({
        key: entry.step,
        label: entry.label,
        color: scaleColor(entry.step, MAP_GROUND),
    })),
);
</script>

<template>
    <ChartCard :title="title" :subtitle="subtitle">
        <template v-if="origins.countries.length > 0">
            <WorldMap :counts="counts" :describe="describeCountry" />

            <div class="-mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                <span class="text-ink-muted text-sm">{{ trans('statistics.origins_legend') }}</span>
                <span v-for="step in legend" :key="step.key" class="text-ink-secondary flex items-center gap-1.5 text-sm">
                    <span class="block size-3.5 rounded-[3px]" :style="{ backgroundColor: step.color }" aria-hidden="true" />
                    {{ step.label }}
                </span>
            </div>
        </template>

        <div class="grid grid-cols-1 gap-x-12 gap-y-8 sm:grid-cols-2">
            <div v-for="list in lists" :key="list.key" class="flex min-w-0 flex-col gap-2.5">
                <h4 class="text-ink m-0 flex items-center gap-1.5 text-base font-semibold">
                    {{ list.label }}
                    <component
                        :is="mediaTypeMeta(code).icon"
                        v-for="code in list.types"
                        :key="code"
                        class="size-4 shrink-0"
                        :style="{ color: mediaTypeMeta(code).color }"
                        aria-hidden="true"
                    />
                </h4>
                <ul class="m-0 flex list-none flex-col gap-2.5 p-0">
                    <li v-for="row in list.rows" :key="row.code" class="flex flex-col gap-1">
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="text-ink-secondary truncate text-base">{{ row.name }}</span>
                            <span class="text-ink-muted shrink-0 text-sm tabular-nums">{{ titles(row.titles) }}</span>
                        </span>
                        <span class="bg-line block h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                            <span
                                class="block h-full rounded-full"
                                :style="{ width: `${(row.titles / list.top) * 100}%`, backgroundColor: list.color }"
                            />
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </ChartCard>
</template>
