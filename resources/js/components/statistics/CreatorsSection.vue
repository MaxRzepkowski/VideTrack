<script setup lang="ts">
import CraftCard from '@/components/statistics/CraftCard.vue';
import StatisticsSection from '@/components/statistics/StatisticsSection.vue';
import { periodTitle, scopeName } from '@/lib/statistics';
import type { PeriodSpan, StatisticsCreators } from '@/types/generated';
import { Users } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/** Who made the period's titles: a card per craft that credits anyone, two to a row. */
const props = defineProps<{
    creators: StatisticsCreators;
    period: PeriodSpan;
    /** The chosen type; null for every type. */
    type: string | null;
}>();

const subtitle = computed(() => trans('statistics.creators_subtitle', { period: periodTitle(props.period), scope: scopeName(props.type) }));
</script>

<template>
    <StatisticsSection v-if="creators.crafts.length > 0" id="creators" :icon="Users" :title="trans('statistics.creators_title')" :subtitle="subtitle">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <CraftCard v-for="craft in creators.crafts" :key="craft.department" :craft="craft" :type="type" />
        </div>
    </StatisticsSection>
</template>
