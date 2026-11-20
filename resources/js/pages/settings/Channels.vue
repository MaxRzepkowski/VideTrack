<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import AddChannelForm from '@/components/settings/channels/AddChannelForm.vue';
import ChannelPanel from '@/components/settings/channels/ChannelPanel.vue';
import PushDevices from '@/components/settings/channels/PushDevices.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { ChannelsPage } from '@/types/generated';
import { Button } from '@/components/ui/button';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import type { TrackingTab } from '@/lib/tracking';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * Where notifications go beyond the bell. Every kind of channel comes from
 * the server's registry: a kind added later needs nothing here.
 */
const props = defineProps<{ page: ChannelsPage }>();

const rows = computed(() =>
    props.page.channels.map((channel) => ({
        channel,
        kind: props.page.kinds.find((kind) => kind.driver === channel.driver && kind.code === channel.kind) ?? null,
    })),
);
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.notifications')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall :title="trans('common.notifications')" :description="trans('settings.where_your_notifications_go_besides_the')" />

                <SettingsCard>
                    <SettingsRow :title="trans('settings.what_you_are_notified_about')" :description="trans('settings.notified_about_description')">
                        <Button variant="outline" class="h-10 py-2" as-child>
                            <Link :href="route('settings.tracking.edit', { tab: 'notifications' satisfies TrackingTab })">
                                {{ trans('settings.choose_what_notifies_you') }}
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </Button>
                    </SettingsRow>
                </SettingsCard>

                <p v-if="props.page.channels.length === 0" class="text-ink-muted text-sm">
                    {{ trans('settings.no_channels_yet_notifications_show_only') }}
                </p>

                <ChannelPanel
                    v-for="row in rows"
                    :key="row.channel.id"
                    :channel="row.channel"
                    :kind="row.kind"
                    :label-max="props.page.labelMax"
                    :digest-hour="props.page.digestHour"
                />

                <PushDevices v-if="props.page.push" :push="props.page.push" />

                <AddChannelForm
                    :kinds="props.page.kinds"
                    :label-max="props.page.labelMax"
                    :digest-hour="props.page.digestHour"
                    :default-mode="props.page.defaultMode"
                />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
