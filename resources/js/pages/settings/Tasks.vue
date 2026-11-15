<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import MetaLine from '@/components/MetaLine.vue';
import { confirm } from '@/composables/useConfirm';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { formatDateTime, relativeTime } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import type { ScheduleEntry, TaskRunItem, TaskRunStatus } from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
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

// "Finished with errors" is derived: success with a non-empty error list.
function badge(run: TaskRunItem): { label: string; classes: string } {
    if (run.status === 'success' && run.errors.length > 0) {
        return { label: trans('tasks.finished_with_errors'), classes: 'bg-status-onhold/15 text-status-onhold' };
    }

    switch (run.status) {
        case 'pending':
            return { label: trans('tasks.pending'), classes: 'bg-ink-muted/15 text-ink-muted' };
        case 'started':
            return { label: trans('tasks.started'), classes: 'bg-status-progress/15 text-status-progress' };
        case 'success':
            return { label: trans('tasks.success'), classes: 'bg-success/15 text-success' };
        case 'failure':
            return { label: trans('tasks.failed'), classes: 'bg-danger/15 text-danger' };
    }
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

// Muted status word next to a scheduled task's last run — red when it failed,
// otherwise the neutral caption colour.
function lastStatusLabel(status: TaskRunStatus): string {
    switch (status) {
        case 'pending':
            return trans('tasks.pending');
        case 'started':
            return trans('tasks.started');
        case 'success':
            return trans('tasks.success');
        case 'failure':
            return trans('tasks.failed');
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
            <div class="space-y-6">
                <HeadingSmall :title="trans('common.tasks')" :description="trans('tasks.background_task_runs_and_the_recurring')" />

                <div v-if="props.schedule.length > 0" class="border-line-subtle bg-surface rounded-xl border px-5 py-4">
                    <div class="text-ink text-base font-semibold">{{ trans('tasks.scheduled_tasks') }}</div>
                    <ul class="divide-line-subtle mt-3 divide-y">
                        <li
                            v-for="entry in props.schedule"
                            :key="entry.name"
                            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2"
                        >
                            <span class="text-ink w-full text-sm sm:w-auto">{{ entry.label }}</span>
                            <div class="flex items-center gap-3">
                                <MetaLine
                                    :parts="[trans(entry.frequency), trans('tasks.next_run_time', { time: formatDateTime(entry.nextRunAt) ?? '' })]"
                                    class="text-ink-muted text-sm"
                                />
                                <button
                                    type="button"
                                    class="text-brand-soft shrink-0 text-sm font-medium hover:underline disabled:opacity-50"
                                    :disabled="running.has(entry.name)"
                                    @click="runNow(entry.name)"
                                >
                                    {{ running.has(entry.name) ? trans('tasks.running') : trans('tasks.run_now') }}
                                </button>
                            </div>
                            <span class="text-ink-faint w-full text-sm">
                                <template v-if="entry.lastRunAt">
                                    {{ trans('tasks.last_run_time', { time: relativeTime(entry.lastRunAt) ?? '' }) }}
                                    <span
                                        v-if="entry.lastStatus"
                                        v-tooltip="formatDateTime(entry.lastRunAt)"
                                        :class="entry.lastStatus === 'failure' ? 'text-danger' : 'text-ink-muted'"
                                    >
                                        <Hairline /><span>{{ lastStatusLabel(entry.lastStatus) }}</span>
                                    </span>
                                </template>
                                <template v-else>{{ trans('tasks.never_run_yet') }}</template>
                            </span>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="text-ink text-base font-semibold">{{ trans('tasks.recent_runs') }}</div>
                    <button
                        v-if="canClear"
                        type="button"
                        class="text-ink-muted hover:text-danger text-sm font-medium hover:underline"
                        @click="clearFinished"
                    >
                        {{ trans('tasks.clear_finished_runs') }}
                    </button>
                </div>

                <div v-if="runs.length === 0" class="border-line-subtle bg-surface text-ink-muted rounded-xl border px-5 py-8 text-center text-sm">
                    {{ trans('tasks.no_recent_task_runs') }}
                </div>

                <div v-else class="space-y-3">
                    <div v-for="run in runs" :key="run.id" class="border-line-subtle bg-surface rounded-xl border px-5 py-4">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="rounded-full px-2.5 py-0.5 text-sm font-semibold" :class="badge(run).classes">
                                {{ badge(run).label }}
                            </span>
                            <span class="text-ink text-base font-semibold">{{ run.label }}</span>
                            <span v-tooltip="formatDateTime(run.queuedAt)" class="text-ink-muted ml-auto text-sm">
                                {{ relativeTime(run.queuedAt) }}
                            </span>
                        </div>
                        <p v-if="run.summary" class="text-ink-muted mt-2 text-sm">{{ run.summary }}</p>
                        <div v-if="run.errors.length > 0" class="mt-2">
                            <button type="button" class="text-status-onhold text-sm font-medium hover:underline" @click="toggle(expanded, run.id)">
                                {{
                                    expanded.has(run.id)
                                        ? trans('tasks.hide_errors')
                                        : transChoice('tasks.show_count_error', run.errors.length, { count: String(run.errors.length) })
                                }}
                            </button>
                            <ul v-if="expanded.has(run.id)" class="bg-surface-base mt-2 space-y-1 rounded-lg p-3">
                                <li v-for="(error, index) in run.errors" :key="index" class="text-danger font-mono text-sm break-words">
                                    {{ error }}
                                </li>
                            </ul>
                        </div>
                        <div v-if="run.details.length > 0" class="mt-2">
                            <button type="button" class="text-ink-muted text-sm font-medium hover:underline" @click="toggle(expandedDetails, run.id)">
                                {{
                                    expandedDetails.has(run.id)
                                        ? trans('tasks.hide_details')
                                        : transChoice('tasks.show_count_detail', run.details.length, { count: String(run.details.length) })
                                }}
                            </button>
                            <ul v-if="expandedDetails.has(run.id)" class="bg-surface-base mt-2 space-y-1 rounded-lg p-3">
                                <li v-for="(detail, index) in run.details" :key="index" class="text-ink-muted text-sm break-words">
                                    {{ detail }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
