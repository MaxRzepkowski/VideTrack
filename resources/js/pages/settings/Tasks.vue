<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import RecentRunsCard from '@/components/settings/tasks/RecentRunsCard.vue';
import ScheduleCard from '@/components/settings/tasks/ScheduleCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { ScheduleEntry, TaskRunItem } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

defineProps<{
    runs: TaskRunItem[];
    schedule: ScheduleEntry[];
    canClear: boolean;
}>();
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.tasks')" />

        <SettingsLayout>
            <div class="space-y-8">
                <HeadingSmall :title="trans('common.tasks')" :description="trans('tasks.background_task_runs_and_the_recurring')" />

                <ScheduleCard v-if="schedule.length > 0" :schedule="schedule" />
                <RecentRunsCard :runs="runs" :can-clear="canClear" />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
