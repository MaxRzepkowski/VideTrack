<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ChannelLogo from '@/components/settings/channels/ChannelLogo.vue';
import CredentialFields from '@/components/settings/CredentialFields.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import { Label } from '@/components/ui/label';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { confirm } from '@/composables/useConfirm';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { channelModes, configError, savedConfig } from '@/lib/channels';
import { SETTINGS_CARD_CLASS, SETTINGS_FIELD_CLASS } from '@/lib/ui';
import type { ChannelData, ChannelKindData } from '@/types/generated';
import { router, useForm } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * One saved channel. Its name, mode and address are a form saved from the
 * bar below; the switch, the test and removal act at once. Without its kind
 * (the instance cannot list its services just now) the address cannot be
 * changed, the rest can.
 */
const props = defineProps<{ channel: ChannelData; kind: ChannelKindData | null; labelMax: number; digestHour: number }>();

/** How long "Test message sent." stays beside the button. */
const TEST_SENT_SHOWN_MS = 3000;

const form = useForm({
    label: props.channel.label,
    mode: props.channel.mode,
    config: savedConfig(props.kind?.fields ?? [], props.channel.values),
});

useUnsavedChanges(() => form.isDirty);

// The fields open on request: the plain values filled in, the secrets empty.
const editingSettings = ref(false);

function save(): void {
    // A new address goes whole, and only when the user opened the fields for one.
    form.transform(({ config, ...rest }) => (editingSettings.value ? { ...rest, config } : rest)).patch(
        route('settings.channels.update', { channel: props.channel.id }),
        {
            preserveScroll: true,
            onSuccess: () => {
                // The page now holds what was saved: the plain values again, the secrets empty.
                form.config = savedConfig(props.kind?.fields ?? [], props.channel.values);
                form.defaults();
                editingSettings.value = false;
                toast.success(trans('settings.saved_2'));
            },
        },
    );
}

function discard(): void {
    form.reset();
    form.clearErrors();
    editingSettings.value = false;
}

function toggle(enabled: boolean): void {
    router.patch(route('settings.channels.update', { channel: props.channel.id }), { enabled }, { preserveScroll: true });
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
        route('settings.channels.test', { channel: props.channel.id }),
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
    const confirmed = await confirm({
        message: trans('settings.remove_this_channel_notifications_stop_going'),
        confirmLabel: trans('settings.remove'),
    });

    if (confirmed) {
        router.delete(route('settings.channels.destroy', { channel: props.channel.id }), { preserveScroll: true });
    }
}
</script>

<template>
    <div :class="[SETTINGS_CARD_CLASS, 'space-y-4']">
        <div class="flex items-center gap-3">
            <ChannelLogo :kind="props.channel.kind" class="h-10 max-w-28" />
            <div class="min-w-0 flex-1">
                <div class="text-ink truncate text-base font-semibold">{{ props.channel.label }}</div>
                <div v-if="props.channel.kindLabel && props.channel.label !== props.channel.kindLabel" class="text-ink-muted text-sm">
                    {{ props.channel.kindLabel }}
                </div>
            </div>
            <ToggleSwitch :model-value="props.channel.enabled" :label="trans('settings.deliver_to_this_channel')" @update:model-value="toggle" />
        </div>

        <p v-if="props.channel.lastFailure" class="text-danger text-sm">{{ props.channel.lastFailure }}</p>

        <fieldset :disabled="form.processing" class="min-w-0 space-y-4">
            <div>
                <Label :for="`channel-${props.channel.id}-label`" class="text-ink text-sm font-medium">{{ trans('common.name') }}</Label>
                <input
                    :id="`channel-${props.channel.id}-label`"
                    v-model="form.label"
                    type="text"
                    :maxlength="props.labelMax"
                    :placeholder="props.channel.kindLabel ?? ''"
                    :class="[SETTINGS_FIELD_CLASS, 'mt-1 block w-full']"
                />
                <InputError class="mt-1" :message="form.errors.label" />
            </div>

            <template v-if="props.kind">
                <CredentialFields
                    v-if="editingSettings"
                    v-model="form.config"
                    keeps-secrets
                    :fields="props.kind.fields"
                    :id-prefix="`channel-${props.channel.id}`"
                    :error-of="(key) => configError(form.errors, key)"
                />
                <InputError v-if="editingSettings" :message="form.errors.config" />
                <span v-if="!editingSettings" class="text-ink block text-sm font-medium">{{ trans('settings.service_settings') }}</span>
                <div v-if="!editingSettings" class="-mt-1 flex items-center justify-between gap-4">
                    <span class="text-ink-muted inline-flex items-center gap-1.5 text-sm">
                        <Check class="text-success size-4" aria-hidden="true" />
                        {{ trans('settings.saved_settings') }}
                    </span>
                    <div class="flex flex-wrap gap-4">
                        <a
                            v-if="props.kind.setupUrl"
                            :href="props.kind.setupUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-brand-soft text-sm font-medium hover:underline"
                        >
                            {{ trans('common.how_to_set_it_up') }}
                        </a>
                        <button type="button" class="text-brand-soft text-sm font-medium hover:underline" @click="editingSettings = true">
                            {{ trans('settings.edit_settings') }}
                        </button>
                    </div>
                </div>
            </template>

            <div>
                <Label :for="`channel-${props.channel.id}-mode`" class="text-ink text-sm font-medium">{{ trans('settings.delivery') }}</Label>
                <select :id="`channel-${props.channel.id}-mode`" v-model="form.mode" :class="[SETTINGS_FIELD_CLASS, 'mt-1 block w-full']">
                    <option v-for="choice in channelModes(props.digestHour)" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                </select>
                <InputError class="mt-1" :message="form.errors.mode" />
            </div>
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
