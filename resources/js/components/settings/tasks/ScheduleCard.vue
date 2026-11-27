<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import MetaLine from '@/components/MetaLine.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime, relativeTime } from '@/lib/format';
import { statusLook } from '@/lib/tasks';
import type { ScheduleEntry } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { LoaderCircle, Play } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

defineProps<{ schedule: ScheduleEntry[] }>();

// Run a scheduled task on demand; the row disables while it dispatches and the
// runs list (which polls active runs) picks it up.
const running = ref<Set<string>>(new Set());

function runNow(name: string): void {
    if (running.value.has(name)) {
        return;
    }

    running.value.add(name);

    router.post(
        route('settings.tasks.run'),
        { name },
        {
            preserveScroll: true,
            only: ['runs', 'schedule', 'canClear'],
            onFinish: () => running.value.delete(name),
        },
    );
}
</script>

<template>
    <SettingsCard :title="trans('tasks.scheduled_tasks')">
        <ul class="divide-line-subtle -my-1 divide-y">
            <li v-for="entry in schedule" :key="entry.name" class="flex flex-wrap items-center gap-x-6 gap-y-3 py-4">
                <div class="min-w-0 flex-1 basis-56">
                    <div class="text-ink text-base font-medium">{{ entry.label }}</div>
                    <MetaLine
                        :parts="[trans(entry.frequency), trans('tasks.next_run_time', { time: formatDateTime(entry.nextRunAt) ?? '' })]"
                        class="text-ink-muted mt-0.5 text-sm"
                        wrap
                    />
                    <div class="mt-1 flex flex-wrap items-center gap-x-2 text-sm">
                        <template v-if="entry.lastRunAt">
                            <template v-if="entry.lastStatus">
                                <component
                                    :is="statusLook(entry.lastStatus).icon"
                                    :class="[
                                        'size-4 shrink-0',
                                        statusLook(entry.lastStatus).tone,
                                        { 'animate-spin': statusLook(entry.lastStatus).spin },
                                    ]"
                                    aria-hidden="true"
                                />
                                <span :class="statusLook(entry.lastStatus).tone">{{ statusLook(entry.lastStatus).label }}</span>
                                <Hairline />
                            </template>
                            <span v-tooltip="formatDateTime(entry.lastRunAt)" class="text-ink-muted">
                                {{ trans('tasks.last_run_time', { time: relativeTime(entry.lastRunAt) ?? '' }) }}
                            </span>
                        </template>
                        <span v-else class="text-ink-faint">{{ trans('tasks.never_run_yet') }}</span>
                    </div>
                </div>
                <Button type="button" variant="outline" size="sm" class="shrink-0" :disabled="running.has(entry.name)" @click="runNow(entry.name)">
                    <LoaderCircle v-if="running.has(entry.name)" class="animate-spin" aria-hidden="true" />
                    <Play v-else aria-hidden="true" />
                    {{ running.has(entry.name) ? trans('tasks.running') : trans('tasks.run_now') }}
                </Button>
            </li>
        </ul>
    </SettingsCard>
</template>
