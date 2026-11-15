<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import WebhookSourceCard from '@/components/settings/integrations/WebhookSourceCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { ScrobbleSourceData } from '@/types/integrations';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

const props = defineProps<{ sources: ScrobbleSourceData[] }>();
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.integrations')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall :title="trans('common.integrations')" :description="trans('integrations.let_your_media_server_tell_this')" />

                <WebhookSourceCard v-for="source in props.sources" :key="source.code" :source="source" />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
