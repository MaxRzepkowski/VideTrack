<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import WebhookFields from '@/components/settings/webhooks/WebhookFields.vue';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { confirm } from '@/composables/useConfirm';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { SETTINGS_CARD_CLASS } from '@/lib/ui';
import { changedWebhook, type WebhookFormValues } from '@/lib/webhooks';
import type { OutgoingWebhookData, WebhookGroupOption } from '@/types/generated';
import { router, useForm } from '@inertiajs/vue3';
import { Webhook } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * One saved webhook. Its form is saved from the bar below, an address or a
 * secret left empty keeping the saved one; the switch, the test and removal
 * act at once.
 */
const props = defineProps<{ webhook: OutgoingWebhookData; groups: WebhookGroupOption[]; labelMax: number; urlMax: number }>();

/** How long "Test message sent." stays beside the button. */
const TEST_SENT_SHOWN_MS = 3000;

const saved = (): WebhookFormValues => ({ label: props.webhook.label, url: '', secret: '', events: [...props.webhook.events] });

const form = useForm<WebhookFormValues>(saved());

useUnsavedChanges(() => form.isDirty);

const listening = computed(() =>
    props.groups
        .filter((group) => props.webhook.events.includes(group.code))
        .map((group) => group.label)
        .join(', '),
);

function save(): void {
    form.transform(changedWebhook).patch(route('settings.webhooks.update', { webhook: props.webhook.id }), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(saved());
            form.reset();
            toast.success(trans('settings.saved_2'));
        },
    });
}

function discard(): void {
    form.reset();
    form.clearErrors();
}

function toggle(enabled: boolean): void {
    router.patch(route('settings.webhooks.update', { webhook: props.webhook.id }), { enabled }, { preserveScroll: true });
}

const testing = ref(false);
const testSent = ref(false);
const testError = ref<string | undefined>();

let testSentTimer: ReturnType<typeof setTimeout> | undefined;

onUnmounted(() => clearTimeout(testSentTimer));

function sendTest(): void {
    testSent.value = false;
    testError.value = undefined;
    router.post(
        route('settings.webhooks.test', { webhook: props.webhook.id }),
        {},
        {
            preserveScroll: true,
            onStart: () => (testing.value = true),
            onFinish: () => (testing.value = false),
            onError: (errors) => (testError.value = errors.test),
            onSuccess: () => {
                testSent.value = true;
                clearTimeout(testSentTimer);
                testSentTimer = setTimeout(() => (testSent.value = false), TEST_SENT_SHOWN_MS);
            },
        },
    );
}

async function remove(): Promise<void> {
    const confirmed = await confirm({ message: trans('settings.remove_this_webhook'), confirmLabel: trans('settings.remove') });

    if (confirmed) {
        router.delete(route('settings.webhooks.destroy', { webhook: props.webhook.id }), { preserveScroll: true });
    }
}
</script>

<template>
    <div :class="[SETTINGS_CARD_CLASS, 'space-y-4']">
        <div class="flex items-center gap-3">
            <span class="bg-surface-raised text-ink-secondary inline-flex size-10 shrink-0 items-center justify-center rounded-xl" aria-hidden="true">
                <Webhook class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="text-ink truncate text-base font-semibold">{{ props.webhook.label }}</div>
                <div v-if="props.webhook.label !== props.webhook.host" class="text-ink-muted truncate text-sm">{{ props.webhook.host }}</div>
            </div>
            <ToggleSwitch :model-value="props.webhook.enabled" :label="trans('settings.deliver_to_this_webhook')" @update:model-value="toggle" />
        </div>

        <p v-if="listening" class="text-ink-secondary text-sm">{{ listening }}</p>
        <p v-if="props.webhook.lastFailure" class="text-danger text-sm">{{ props.webhook.lastFailure }}</p>

        <fieldset :disabled="form.processing" class="min-w-0 space-y-4">
            <WebhookFields
                v-model="form"
                saved
                :id-prefix="`webhook-${props.webhook.id}`"
                :groups="props.groups"
                :new-secret="props.webhook.newSecret"
                :label-max="props.labelMax"
                :url-max="props.urlMax"
                :errors="form.errors"
            />
        </fieldset>

        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <span v-if="testSent" class="text-ink-muted text-sm" role="status">{{ trans('settings.test_message_sent') }}</span>
            <InputError v-else :message="testError" />
            <div class="ml-auto flex flex-wrap gap-4">
                <button
                    type="button"
                    class="text-brand-soft text-sm font-medium hover:underline disabled:opacity-50"
                    :disabled="testing"
                    @click="sendTest"
                >
                    {{ testing ? trans('settings.sending') : trans('settings.send_a_test_message') }}
                </button>
                <button type="button" class="text-danger text-sm font-medium hover:underline" @click="remove">
                    {{ trans('settings.remove') }}
                </button>
            </div>
        </div>

        <SettingsSaveBar v-if="form.isDirty || form.processing" :dirty="form.isDirty" :processing="form.processing" @save="save" @discard="discard" />
    </div>
</template>
