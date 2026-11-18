<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import AddWebhookForm from '@/components/settings/webhooks/AddWebhookForm.vue';
import WebhookPanel from '@/components/settings/webhooks/WebhookPanel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { OutgoingWebhooksPage } from '@/types/generated';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

/** Addresses of the user's own that hear about what happens in their library. */
const props = defineProps<{ page: OutgoingWebhooksPage }>();
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.webhooks')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall :title="trans('common.webhooks')" :description="trans('settings.webhooks_page_description')" />

                <p v-if="props.page.webhooks.length === 0" class="text-ink-muted text-sm">{{ trans('settings.no_webhooks_yet') }}</p>

                <WebhookPanel
                    v-for="webhook in props.page.webhooks"
                    :key="webhook.id"
                    :webhook="webhook"
                    :groups="props.page.groups"
                    :label-max="props.page.labelMax"
                    :url-max="props.page.urlMax"
                />

                <AddWebhookForm
                    v-if="props.page.webhooks.length < props.page.limit"
                    :groups="props.page.groups"
                    :new-secret="props.page.newSecret"
                    :label-max="props.page.labelMax"
                    :url-max="props.page.urlMax"
                />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
