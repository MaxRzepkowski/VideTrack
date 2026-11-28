<script setup lang="ts">
import DeliveryLog from '@/components/settings/integrations/DeliveryLog.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { confirm } from '@/composables/useConfirm';
import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { formatDateTime, relativeTime } from '@/lib/format';
import type { ScrobbleSourceData, WebhookEndpointData } from '@/types/integrations';
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

/**
 * One address and what has come in through it. Deliberately flat: an input,
 * two fields, a row of actions and the log — no boxes inside boxes and no
 * status pills, which turn a page that should read like settings into a
 * dashboard.
 */
const props = defineProps<{ source: ScrobbleSourceData; endpoint: WebhookEndpointData }>();

const form = useForm({
    label: props.endpoint.label ?? '',
    enabled: props.endpoint.enabled,
    server_username: props.endpoint.serverUsername ?? '',
});

useUnsavedChanges(() => form.isDirty);

const { copied, copy } = useCopyToClipboard();

function save(): void {
    form.patch(route('settings.integrations.update', { endpoint: props.endpoint.id }), {
        preserveScroll: true,
        onSuccess: () => form.defaults().reset(),
    });
}

function discard(): void {
    form.reset();
    form.clearErrors();
}

function runTest(): void {
    router.post(route('settings.integrations.test', { endpoint: props.endpoint.id }), {}, { preserveScroll: true, only: ['sources'] });
}

async function rotate(): Promise<void> {
    const confirmed = await confirm({
        message: trans('integrations.give_this_endpoint_a_new_address'),
        confirmLabel: trans('integrations.new_address'),
    });

    if (!confirmed) {
        return;
    }

    router.post(route('settings.integrations.rotate', { endpoint: props.endpoint.id }), {}, { preserveScroll: true });
}

async function disconnect(): Promise<void> {
    const confirmed = await confirm({
        message: trans('integrations.disconnect_this_endpoint_its_address_and'),
        confirmLabel: trans('common.disconnect'),
    });

    if (!confirmed) {
        return;
    }

    router.delete(route('settings.integrations.destroy', { endpoint: props.endpoint.id }), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex gap-2">
            <input
                :id="`url-${props.endpoint.id}`"
                readonly
                :value="props.endpoint.url"
                :aria-label="trans('integrations.address_for_your_server')"
                class="border-line-subtle bg-surface-base text-ink-secondary focus:border-brand min-w-0 flex-1 rounded-md border px-3 py-2 font-mono text-sm focus:outline-none"
                @focus="($event.target as HTMLInputElement).select()"
            />
            <button
                type="button"
                class="border-line-subtle bg-surface-base text-ink hover:border-brand shrink-0 rounded-md border px-4 py-2 text-sm font-medium"
                @click="copy(props.endpoint.url)"
            >
                {{ copied ? trans('common.copied') : trans('common.copy') }}
            </button>
        </div>

        <p v-if="props.endpoint.loopback" class="text-status-onhold -mt-2 text-sm">
            {{ trans('integrations.a_server_running_in_its_own') }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label :for="`label-${props.endpoint.id}`" class="text-ink mb-1 block text-sm font-medium">{{ trans('common.name') }}</label>
                <input
                    :id="`label-${props.endpoint.id}`"
                    v-model="form.label"
                    type="text"
                    maxlength="60"
                    :placeholder="trans('integrations.living_room')"
                    class="border-line-subtle bg-surface-base text-ink focus:border-brand w-full rounded-md border px-3 py-2 text-base focus:outline-none"
                />
                <p v-if="form.errors.label" class="text-danger mt-1 text-sm">{{ form.errors.label }}</p>
            </div>

            <div>
                <label :for="`username-${props.endpoint.id}`" class="text-ink mb-1 block text-sm font-medium">
                    {{ trans('integrations.account_on_the_server') }}
                </label>
                <input
                    :id="`username-${props.endpoint.id}`"
                    v-model="form.server_username"
                    type="text"
                    maxlength="100"
                    autocomplete="off"
                    :placeholder="trans('integrations.every_account')"
                    class="border-line-subtle bg-surface-base text-ink focus:border-brand w-full rounded-md border px-3 py-2 text-base focus:outline-none"
                />
                <p v-if="form.errors.server_username" class="text-danger mt-1 text-sm">{{ form.errors.server_username }}</p>
            </div>
        </div>

        <label class="flex items-center justify-between gap-3">
            <span class="text-ink text-sm">{{ trans('integrations.accept_deliveries') }}</span>
            <ToggleSwitch v-model="form.enabled" />
        </label>

        <p v-if="props.endpoint.lastError" class="text-danger text-sm">{{ props.endpoint.lastError }}</p>

        <SettingsSaveBar placement="card" :dirty="form.isDirty" :processing="form.processing" @save="save" @discard="discard" />

        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <span v-if="props.endpoint.lastReceivedAt" v-tooltip="formatDateTime(props.endpoint.lastReceivedAt)" class="text-ink-muted text-sm">
                {{ trans('integrations.last_delivery_time', { time: relativeTime(props.endpoint.lastReceivedAt) ?? '' }) }}
            </span>
            <span v-else class="text-ink-muted text-sm">{{ trans('integrations.nothing_has_arrived_yet') }}</span>

            <div class="flex flex-wrap gap-4">
                <button v-if="props.source.canTest" type="button" class="text-brand-soft text-sm font-medium hover:underline" @click="runTest">
                    {{ trans('integrations.send_a_sample_through') }}
                </button>
                <button type="button" class="text-brand-soft text-sm font-medium hover:underline" @click="rotate">
                    {{ trans('integrations.new_address') }}
                </button>
                <button type="button" class="text-danger text-sm font-medium hover:underline" @click="disconnect">
                    {{ trans('common.disconnect') }}
                </button>
            </div>
        </div>

        <DeliveryLog v-if="props.endpoint.deliveries.length > 0" :deliveries="props.endpoint.deliveries" />
    </div>
</template>
