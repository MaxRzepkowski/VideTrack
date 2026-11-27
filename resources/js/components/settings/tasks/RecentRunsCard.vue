<script setup lang="ts">
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { Button } from '@/components/ui/button';
import { confirm } from '@/composables/useConfirm';
import { usePolledWhile } from '@/composables/usePolledWhile';
import { formatDateTime, relativeTime } from '@/lib/format';
import { isUnderWay, statusLook, type StatusLook } from '@/lib/tasks';
import type { TaskRunItem } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { ChevronDown, Trash2 } from '@lucide/vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { ref } from 'vue';

const props = defineProps<{
    runs: TaskRunItem[];
    canClear: boolean;
}>();

// The runs as last asked for: the page's, until a poll brings newer ones,
// and the page's again after every visit (running a task, clearing). While
// a run is under way (an import in progress) they are asked for again, so
// its summary visibly moves.
const polled = usePolledWhile(
    () => ({ runs: props.runs, canClear: props.canClear }),
    () => route('settings.tasks.runs'),
    (list) => list.runs.some((run) => isUnderWay(run.status)),
);

// "Finished with errors" is derived: success with a non-empty error list.
// A run says its ending in a word only: the list reads as text, the schedule above carries the icons.
function runLook(run: TaskRunItem): Pick<StatusLook, 'label' | 'tone'> {
    return run.status === 'success' && run.errors.length > 0
        ? { label: trans('tasks.finished_with_errors'), tone: 'text-status-onhold' }
        : statusLook(run.status);
}

const expanded = ref<Set<number>>(new Set());
const expandedDetails = ref<Set<number>>(new Set());

function toggle(open: Set<number>, id: number): void {
    if (open.has(id)) {
        open.delete(id);
    } else {
        open.add(id);
    }
}

async function clearFinished(): Promise<void> {
    if (!(await confirm({ message: trans('tasks.clear_the_finished_task_history'), confirmLabel: trans('tasks.clear_finished_runs') }))) {
        return;
    }

    router.delete(route('settings.tasks.destroy'), { preserveScroll: true, only: ['runs', 'canClear'] });
}
</script>

<template>
    <SettingsCard :title="trans('tasks.recent_runs')">
        <template v-if="polled.canClear" #actions>
            <Button type="button" variant="ghost" size="sm" class="text-ink-muted hover:text-danger" @click="clearFinished">
                <Trash2 aria-hidden="true" />
                {{ trans('tasks.clear_finished_runs') }}
            </Button>
        </template>

        <p v-if="polled.runs.length === 0" class="text-ink-muted py-4 text-center text-sm">{{ trans('tasks.no_recent_task_runs') }}</p>

        <ul v-else class="divide-line-subtle -my-1 divide-y">
            <li v-for="run in polled.runs" :key="run.id" class="py-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                        <span class="text-ink text-base font-medium">{{ run.label }}</span>
                        <span :class="['text-sm font-medium', runLook(run).tone]">{{ runLook(run).label }}</span>
                        <span v-tooltip="formatDateTime(run.queuedAt)" class="text-ink-muted ml-auto text-sm">
                            {{ relativeTime(run.queuedAt) }}
                        </span>
                    </div>
                    <p v-if="run.summary" class="text-ink-muted mt-1 text-sm">{{ run.summary }}</p>

                    <div v-if="run.errors.length > 0 || run.details.length > 0" class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                        <button
                            v-if="run.errors.length > 0"
                            type="button"
                            class="text-status-onhold flex items-center gap-1 text-sm font-medium hover:underline"
                            :aria-expanded="expanded.has(run.id)"
                            @click="toggle(expanded, run.id)"
                        >
                            {{
                                expanded.has(run.id)
                                    ? trans('tasks.hide_errors')
                                    : transChoice('tasks.show_count_error', run.errors.length, { count: String(run.errors.length) })
                            }}
                            <ChevronDown :class="['size-4 transition-transform', { 'rotate-180': expanded.has(run.id) }]" aria-hidden="true" />
                        </button>
                        <button
                            v-if="run.details.length > 0"
                            type="button"
                            class="text-ink-muted hover:text-ink flex items-center gap-1 text-sm font-medium hover:underline"
                            :aria-expanded="expandedDetails.has(run.id)"
                            @click="toggle(expandedDetails, run.id)"
                        >
                            {{
                                expandedDetails.has(run.id)
                                    ? trans('tasks.hide_details')
                                    : transChoice('tasks.show_count_detail', run.details.length, {
                                          count: String(run.details.length),
                                      })
                            }}
                            <ChevronDown :class="['size-4 transition-transform', { 'rotate-180': expandedDetails.has(run.id) }]" aria-hidden="true" />
                        </button>
                    </div>
                    <ul v-if="expanded.has(run.id)" class="bg-surface-base mt-2 space-y-1 rounded-lg p-3">
                        <li v-for="(error, index) in run.errors" :key="index" class="text-danger font-mono text-sm break-words">
                            {{ error }}
                        </li>
                    </ul>
                    <ul v-if="expandedDetails.has(run.id)" class="bg-surface-base mt-2 space-y-1 rounded-lg p-3">
                        <li v-for="(detail, index) in run.details" :key="index" class="text-ink-muted text-sm break-words">
                            {{ detail }}
                        </li>
                    </ul>
                </div>
            </li>
        </ul>
    </SettingsCard>
</template>
