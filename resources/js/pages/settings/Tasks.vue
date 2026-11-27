<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import RecentRunsCard from '@/components/settings/tasks/RecentRunsCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { TaskRunItem } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

// A user's own runs; an admin's, with the schedule, are a tab of the Instance page.
defineProps<{
    runs: TaskRunItem[];
    canClear: boolean;
}>();
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.tasks')" />

        <SettingsLayout>
            <div class="space-y-8">
                <HeadingSmall :title="trans('common.tasks')" :description="trans('tasks.background_task_runs_and_the_recurring')" />

                <RecentRunsCard :runs="runs" :can-clear="canClear" />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
