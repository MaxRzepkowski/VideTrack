<script setup lang="ts">
import InstanceHeader from '@/components/settings/InstanceHeader.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { formatDateTime } from '@/lib/format';
import { levelLook, wordedText, writtenMoment } from '@/lib/tasks';
import type { AttentionItem } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

defineProps<{
    attention: AttentionItem[];
    /** When the maintenance checks last looked, so an empty list reads as checked. */
    checkedAt: string | null;
}>();
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.instance')" />

        <SettingsLayout>
            <div class="space-y-8">
                <InstanceHeader active="overview" />

                <SettingsCard :title="trans('tasks.needs_attention')">
                    <ul v-if="attention.length > 0" class="divide-line-subtle -my-1 divide-y">
                        <!-- A few short sentences (what it stops, what to do) read best as one paragraph, not a list. -->
                        <li v-for="(item, index) in attention" :key="index" class="flex items-start gap-3 py-4">
                            <component
                                :is="levelLook(item.level).icon"
                                :class="['mt-1 size-4 shrink-0', levelLook(item.level).tone]"
                                aria-hidden="true"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                                    <span class="text-ink text-base font-medium">{{ item.title }}</span>
                                    <span v-if="item.since" class="text-ink-muted text-sm whitespace-nowrap">
                                        {{ trans('tasks.since_time', { time: writtenMoment(item.since) }) }}
                                    </span>
                                </div>
                                <p v-if="item.details.length > 0" class="text-ink-muted mt-1 max-w-prose text-sm leading-relaxed">
                                    {{ item.details.map(wordedText).join(' ') }}
                                </p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-ink-muted py-4 text-sm">
                        {{
                            checkedAt
                                ? trans('tasks.everything_works_checked_time', { time: formatDateTime(checkedAt) ?? '' })
                                : trans('tasks.nothing_needs_your_attention')
                        }}
                    </p>
                </SettingsCard>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
