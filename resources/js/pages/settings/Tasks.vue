<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { Button } from '@/components/ui/button';
import MetaLine from '@/components/MetaLine.vue';
import { confirm } from '@/composables/useConfirm';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { formatDateTime, relativeTime } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import type { ScheduleEntry, TaskRunItem, TaskRunStatus } from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
import { ChevronDown, CircleCheck, CircleX, Clock, LoaderCircle, Play, Trash2, type LucideIcon } from '@lucide/vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
    runs: TaskRunItem[];
    schedule: ScheduleEntry[];
    canClear: boolean;
}>();

// The runs as last asked for: the page's, until a poll brings newer ones,
// and the page's again after every visit (running a task, clearing).
const runs = ref(props.runs);
const canClear = ref(props.canClear);

// Bumped by every visit's props: a poll sent before them answers about a
// list the visit has already replaced, so its answer is dropped.
let generation = 0;

watch(
    () => [props.runs, props.canClear] as const,
    ([fresh, clearable]) => {
        generation++;
        runs.value = fresh;
        canClear.value = clearable;
    },
);

/** @view How a run's ending reads: its word, its icon and their colour. */
interface StatusLook {
    label: string;
    icon: LucideIcon;
    /** The colour of the icon and the word. */
    tone: string;
    spin?: boolean;
}

function statusLook(status: TaskRunStatus): StatusLook {
    switch (status) {
        case 'pending':
            return { label: trans('tasks.pending'), icon: Clock, tone: 'text-ink-muted' };
        case 'started':
            return { label: trans('tasks.started'), icon: LoaderCircle, tone: 'text-status-progress', spin: true };
        case 'success':
            return { label: trans('tasks.success'), icon: CircleCheck, tone: 'text-success' };
        case 'failure':
            return { label: trans('tasks.failed'), icon: CircleX, tone: 'text-danger' };
    }
}

// "Finished with errors" is derived: success with a non-empty error list.
// A run says its ending in a word only: the list reads as text, the schedule above carries the icons.
function runLook(run: TaskRunItem): Pick<StatusLook, 'label' | 'tone'> {
    return run.status === 'success' && run.errors.length > 0
        ? { label: trans('tasks.finished_with_errors'), tone: 'text-status-onhold' }
        : statusLook(run.status);
}

// While a run is pending/started (e.g. an import in progress) the list asks
// again, so its summary visibly moves. A plain fetch, never `router`: a visit
// would repaint the page under whoever is reading it.
const POLL_MS = 3000;
const hasActiveRun = computed(() => runs.value.some((run) => run.status === 'pending' || run.status === 'started'));

// One poll at a time: a slow server must not stack them up. A poll that
// fails is skipped; the next tick asks again.
let polling = false;

async function poll(): Promise<void> {
    polling = true;
    const sentAt = generation;

    try {
        const response = await jsonFetch(route('settings.tasks.runs'));

        if (response.ok) {
            const fresh = (await response.json()) as { runs: TaskRunItem[]; canClear: boolean };

            if (sentAt === generation) {
                runs.value = fresh.runs;
                canClear.value = fresh.canClear;
            }
        }
    } catch {
        // Offline for a moment: the next tick asks again.
    } finally {
        polling = false;
    }
}

const pollTimer = window.setInterval(() => {
    if (hasActiveRun.value && !polling) {
        void poll();
    }
}, POLL_MS);

onUnmounted(() => window.clearInterval(pollTimer));

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
    <AppLayout>
        <Head :title="trans('common.tasks')" />

        <SettingsLayout>
            <div class="space-y-8">
                <HeadingSmall :title="trans('common.tasks')" :description="trans('tasks.background_task_runs_and_the_recurring')" />

                <SettingsCard v-if="props.schedule.length > 0" :title="trans('tasks.scheduled_tasks')">
                    <ul class="divide-line-subtle -my-1 divide-y">
                        <li v-for="entry in props.schedule" :key="entry.name" class="flex flex-wrap items-center gap-x-6 gap-y-3 py-4">
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
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="shrink-0"
                                :disabled="running.has(entry.name)"
                                @click="runNow(entry.name)"
                            >
                                <LoaderCircle v-if="running.has(entry.name)" class="animate-spin" aria-hidden="true" />
                                <Play v-else aria-hidden="true" />
                                {{ running.has(entry.name) ? trans('tasks.running') : trans('tasks.run_now') }}
                            </Button>
                        </li>
                    </ul>
                </SettingsCard>

                <SettingsCard :title="trans('tasks.recent_runs')">
                    <template v-if="canClear" #actions>
                        <Button type="button" variant="ghost" size="sm" class="text-ink-muted hover:text-danger" @click="clearFinished">
                            <Trash2 aria-hidden="true" />
                            {{ trans('tasks.clear_finished_runs') }}
                        </Button>
                    </template>

                    <p v-if="runs.length === 0" class="text-ink-muted py-4 text-center text-sm">{{ trans('tasks.no_recent_task_runs') }}</p>

                    <ul v-else class="divide-line-subtle -my-1 divide-y">
                        <li v-for="run in runs" :key="run.id" class="py-4">
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
                                        <ChevronDown
                                            :class="['size-4 transition-transform', { 'rotate-180': expanded.has(run.id) }]"
                                            aria-hidden="true"
                                        />
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
                                        <ChevronDown
                                            :class="['size-4 transition-transform', { 'rotate-180': expandedDetails.has(run.id) }]"
                                            aria-hidden="true"
                                        />
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
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
