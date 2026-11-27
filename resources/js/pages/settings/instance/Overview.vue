<script setup lang="ts">
import InstanceHeader from '@/components/settings/InstanceHeader.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { formatDateTime } from '@/lib/format';
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
                        <li v-for="(item, index) in attention" :key="index" class="py-4">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                                <span class="text-danger text-base font-medium">{{ item.title }}</span>
                                <span v-if="item.since" class="text-ink-muted ml-auto text-sm">
                                    {{ trans('tasks.since_time', { time: formatDateTime(item.since) ?? '' }) }}
                                </span>
                            </div>
                            <p class="text-ink-muted mt-1 text-sm">{{ item.detail }}</p>
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
