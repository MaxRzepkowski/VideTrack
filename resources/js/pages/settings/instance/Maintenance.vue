<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import MetaLine from '@/components/MetaLine.vue';
import InstanceHeader from '@/components/settings/InstanceHeader.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import { Button } from '@/components/ui/button';
import { usePolledWhile } from '@/composables/usePolledWhile';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { formatDateTime, relativeTime } from '@/lib/format';
import { isUnderWay, levelLook, statusLook, wordedText } from '@/lib/tasks';
import type { MaintenanceCheckEntry } from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
import { LoaderCircle, Play } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

const props = defineProps<{ checks: MaintenanceCheckEntry[] }>();

// While a check is queued or at work its row asks again until it ends.
const polled = usePolledWhile(
    () => ({ checks: props.checks }),
    () => route('settings.instance.checks.index'),
    (list) => list.checks.some((check) => isUnderWay(check.status)),
);

// Run a check on demand; the row disables while it dispatches and then while
// the check is under way.
const running = ref<Set<string>>(new Set());

function runNow(code: string): void {
    if (running.value.has(code)) {
        return;
    }

    running.value.add(code);

    router.post(
        route('settings.instance.checks.run'),
        { code },
        {
            preserveScroll: true,
            only: ['checks'],
            onFinish: () => running.value.delete(code),
        },
    );
}

function busy(check: MaintenanceCheckEntry): boolean {
    return running.value.has(check.code) || isUnderWay(check.status);
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.instance')" />

        <SettingsLayout>
            <div class="space-y-8">
                <InstanceHeader active="maintenance" />

                <SettingsCard :title="trans('tasks.maintenance')">
                    <p v-if="polled.checks.length === 0" class="text-ink-muted py-4 text-center text-sm">
                        {{ trans('tasks.no_maintenance_checks') }}
                    </p>

                    <ul v-else class="divide-line-subtle -my-1 divide-y">
                        <li v-for="check in polled.checks" :key="check.code" class="flex flex-wrap items-start gap-x-6 gap-y-3 py-4">
                            <div class="min-w-0 flex-1 basis-56">
                                <div class="text-ink text-base font-medium">{{ check.label }}</div>
                                <MetaLine :parts="[trans(check.frequency)]" class="text-ink-muted mt-0.5 text-sm" wrap />

                                <!-- Where it stands, as a scheduled task's last run reads. -->
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 text-sm">
                                    <template v-if="check.status === 'started'">
                                        <LoaderCircle :class="['size-4 shrink-0 animate-spin', statusLook('started').tone]" aria-hidden="true" />
                                        <span :class="statusLook('started').tone">
                                            {{ trans('tasks.running_since_time', { time: formatDateTime(check.startedAt) ?? '' }) }}
                                        </span>
                                    </template>
                                    <template v-else-if="check.status">
                                        <component
                                            :is="statusLook(check.status).icon"
                                            :class="['size-4 shrink-0', statusLook(check.status).tone]"
                                            aria-hidden="true"
                                        />
                                        <span :class="statusLook(check.status).tone">{{ statusLook(check.status).label }}</span>
                                        <template v-if="check.checkedAt">
                                            <Hairline />
                                            <span v-tooltip="formatDateTime(check.checkedAt)" class="text-ink-muted">
                                                {{ trans('tasks.checked_time', { time: relativeTime(check.checkedAt) ?? '' }) }}
                                            </span>
                                        </template>
                                    </template>
                                    <span v-else class="text-ink-faint">{{ trans('tasks.not_checked_yet') }}</span>
                                </div>

                                <p v-if="check.error" class="text-danger mt-2 text-sm">{{ check.error }}</p>

                                <ul v-if="check.lines.length > 0" class="mt-3 space-y-2 text-sm">
                                    <li v-for="(line, index) in check.lines" :key="index" class="flex items-start gap-2">
                                        <component
                                            :is="levelLook(line.level).icon"
                                            :class="['mt-0.5 size-4 shrink-0', levelLook(line.level).tone]"
                                            aria-hidden="true"
                                        />
                                        <div class="min-w-0">
                                            <span :class="levelLook(line.level).tone">{{ wordedText(line.line) }}</span>
                                            <p v-for="(detail, at) in line.details" :key="at" class="text-ink-muted">{{ wordedText(detail) }}</p>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                            <Button type="button" variant="outline" size="sm" class="shrink-0" :disabled="busy(check)" @click="runNow(check.code)">
                                <LoaderCircle v-if="busy(check)" class="animate-spin" aria-hidden="true" />
                                <Play v-else aria-hidden="true" />
                                {{ busy(check) ? trans('tasks.running') : trans('tasks.run_now') }}
                            </Button>
                        </li>
                    </ul>
                </SettingsCard>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
