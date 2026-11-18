<script setup lang="ts">
import WebhookFields from '@/components/settings/webhooks/WebhookFields.vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { SETTINGS_CARD_CLASS } from '@/lib/ui';
import type { WebhookFormValues } from '@/lib/webhooks';
import type { WebhookGroupOption } from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

/** A new webhook: its address, a secret Generate puts in, the events it hears about and a name. */
const props = defineProps<{ groups: WebhookGroupOption[]; newSecret: string; labelMax: number; urlMax: number }>();

const form = useForm<WebhookFormValues>({ label: '', url: '', secret: '', events: [] });

useUnsavedChanges(() => form.isDirty);

function add(): void {
    form.post(route('settings.webhooks.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            toast.success(trans('settings.webhook_added'));
        },
    });
}
</script>

<template>
    <form :class="[SETTINGS_CARD_CLASS, 'space-y-4']" @submit.prevent="add">
        <div class="text-ink text-base font-semibold">{{ trans('settings.add_a_webhook') }}</div>

        <fieldset :disabled="form.processing" class="min-w-0 space-y-4">
            <WebhookFields
                v-model="form"
                id-prefix="new-webhook"
                :groups="props.groups"
                :new-secret="props.newSecret"
                :label-max="props.labelMax"
                :url-max="props.urlMax"
                :errors="form.errors"
            />
        </fieldset>

        <button
            type="submit"
            class="bg-brand hover:bg-brand/90 text-on-accent inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold disabled:opacity-50"
            :disabled="form.processing"
        >
            {{ trans('settings.add_webhook') }}
        </button>
    </form>
</template>
