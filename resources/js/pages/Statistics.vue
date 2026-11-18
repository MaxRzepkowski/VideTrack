<script setup lang="ts">
import StatTile from '@/components/dashboard/StatTile.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeading from '@/components/PageHeading.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import ActivitySection from '@/components/statistics/ActivitySection.vue';
import BookendsCard from '@/components/statistics/BookendsCard.vue';
import PeriodBar from '@/components/statistics/PeriodBar.vue';
import PeriodCard from '@/components/statistics/PeriodCard.vue';
import TasteSection from '@/components/statistics/TasteSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { statisticsParams, type StatisticsQuery } from '@/lib/statistics';
import type { StatisticsActivity, StatisticsOrigins, StatisticsPage, StatisticsTaste } from '@/types/generated';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { ChartNoAxesColumn } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * What the viewer finished in a period, from their own history only. The
 * strip under the heading picks the period and the type; the page reads them
 * from its address, so every view can be linked to and walked back through.
 */
const props = defineProps<{
    page: StatisticsPage;
    /** Deferred: absent until the follow-up request lands. */
    activity?: StatisticsActivity;
    /** Deferred: absent until the follow-up request lands. */
    taste?: StatisticsTaste;
    /** Deferred, with `taste`. */
    origins?: StatisticsOrigins;
}>();

function visit(query: StatisticsQuery): void {
    router.get(route('statistics'), statisticsParams(query), { preserveScroll: true });
}

const hasTitles = computed(() => props.page.titles.titles.total > 0);
</script>

<template>
    <AppLayout>
        <Head :title="trans('statistics.title')" />

        <PageMasthead>
            <div class="max-w-content mx-auto w-full px-4 pt-8 pb-6 md:px-10">
                <PageHeading :title="trans('statistics.title')" :subtitle="trans('statistics.subtitle')">
                    <template #icon><ChartNoAxesColumn class="size-6" /></template>
                </PageHeading>
            </div>
        </PageMasthead>

        <PeriodBar :page="page" @visit="visit" />

        <div class="max-w-content mx-auto flex w-full flex-1 flex-col gap-6 px-4 pt-10 pb-20 md:px-10">
            <PeriodCard
                :period="page.period"
                :summary="page.summary"
                :titles="page.titles"
                :type="page.type"
                :stats="page.type === null ? null : (page.tiles[page.type] ?? null)"
            />

            <template v-if="hasTitles">
                <!-- Every type: a tile each. One type is said in the summary above, as simply as it goes. -->
                <div v-if="page.type === null" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatTile v-for="(stats, kind) in page.tiles" :key="kind" :kind="kind" :stats="stats" />
                </div>
                <BookendsCard v-if="page.first && page.last" :first="page.first" :last="page.last" :period="page.period" />

                <Deferred data="activity">
                    <template #fallback>
                        <div class="border-line-subtle bg-surface mt-12 h-96 animate-pulse rounded-xl border" aria-hidden="true" />
                    </template>
                    <ActivitySection v-if="activity" :activity="activity" :period="page.period" :type="page.type" />
                </Deferred>

                <Deferred :data="['taste', 'origins']">
                    <template #fallback>
                        <div class="border-line-subtle bg-surface mt-12 h-96 animate-pulse rounded-xl border" aria-hidden="true" />
                    </template>
                    <TasteSection v-if="taste && origins" :taste="taste" :origins="origins" :period="page.period" :type="page.type" />
                </Deferred>
            </template>

            <EmptyState v-else :message="trans('statistics.no_completions')" />
        </div>
    </AppLayout>
</template>
