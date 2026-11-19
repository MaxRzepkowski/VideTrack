<script setup lang="ts">
import ChannelLogo from '@/components/settings/channels/ChannelLogo.vue';
import { confirm } from '@/composables/useConfirm';
import { usePushSubscription, type NewPushSubscription } from '@/composables/usePushSubscription';
import { describeBrowser } from '@/lib/browsers';
import { formatTimestampDate } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import type { PushSupport } from '@/lib/push';
import { SETTINGS_CARD_CLASS } from '@/lib/ui';
import type { PushDeviceData, PushDevicesData } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Notifications in the browser: the devices they reach, the one this page is
 * open in marked, and the button that switches them on here. The browser asks
 * for permission only after that click.
 */
const props = defineProps<{ push: PushDevicesData }>();

const { support, fingerprint, subscribe, unsubscribe } = usePushSubscription(() => props.push.publicKey);

const busy = ref(false);

const rows = computed(() =>
    props.push.devices.map((device) => ({
        device,
        ...describeBrowser(device.userAgent),
        current: device.fingerprint === fingerprint.value,
        since: formatTimestampDate(device.subscribedAt),
    })),
);

const currentRow = computed(() => rows.value.find((row) => row.current) ?? null);

const NOTICES: Record<Exclude<PushSupport, 'ready'>, string> = {
    insecure: 'settings.push_needs_https',
    'install-first': 'settings.push_on_ios_add_to_home_screen',
    unsupported: 'settings.push_not_supported',
    blocked: 'settings.push_blocked_in_browser',
};

const notice = computed(() => (support.value === null || support.value === 'ready' ? null : trans(NOTICES[support.value])));

// Here already and still heard: nothing to switch on.
const offersButton = computed(() => support.value === 'ready' && (currentRow.value === null || currentRow.value.device.stale));

function failed(): void {
    busy.value = false;
    toast.error(trans('settings.push_could_not_turn_on'));
}

async function turnOn(): Promise<void> {
    busy.value = true;
    let made: NewPushSubscription | null;

    try {
        made = await subscribe();
    } catch (error) {
        // The browser or its push service refused (no network, a push service down).
        if (error instanceof DOMException) {
            failed();

            return;
        }

        throw error;
    }

    if (made === null) {
        busy.value = false;

        return;
    }

    // A subscription to an older key pair takes its old row's place, if the row is this account's.
    if (made.replaced !== null) {
        const renewal = await jsonFetch(route('settings.channels.devices.renew'), {
            method: 'POST',
            body: { ...made.body, previous_endpoint: made.replaced },
        });

        if (renewal.ok) {
            router.reload({ only: ['page'], onFinish: () => (busy.value = false) });

            return;
        }
    }

    router.post(
        route('settings.channels.devices.store'),
        { ...made.body },
        { preserveScroll: true, onError: failed, onFinish: () => (busy.value = false) },
    );
}

async function remove(device: PushDeviceData, current: boolean): Promise<void> {
    const confirmed = await confirm({
        message: trans('settings.remove_this_device_it_stops_getting'),
        confirmLabel: trans('settings.remove'),
    });

    if (!confirmed) {
        return;
    }

    if (current) {
        await unsubscribe();
    }

    router.delete(route('settings.channels.devices.destroy', { device: device.id }), { preserveScroll: true });
}
</script>

<template>
    <div :class="[SETTINGS_CARD_CLASS, 'space-y-4']">
        <div class="flex items-center gap-3">
            <ChannelLogo kind="webpush" class="h-10" />
            <div class="min-w-0 flex-1">
                <div class="text-ink text-base font-semibold">{{ trans('settings.browser_notifications') }}</div>
                <div class="text-ink-muted text-sm">{{ trans('settings.push_on_each_device_separately') }}</div>
            </div>
        </div>

        <ul v-if="rows.length > 0" class="divide-line-subtle divide-y">
            <li v-for="row in rows" :key="row.device.id" class="flex items-start gap-4 py-3 first:pt-0">
                <div class="min-w-0 flex-1 space-y-0.5">
                    <div class="flex flex-wrap items-center gap-x-2 text-sm">
                        <span class="text-ink font-medium">{{ row.browser ?? trans('settings.unknown_browser') }}</span>
                        <template v-if="row.system">
                            <span class="bg-line h-2 w-px shrink-0" aria-hidden="true" />
                            <span class="text-ink-secondary">{{ row.system }}</span>
                        </template>
                        <template v-if="row.current">
                            <span class="bg-line h-2 w-px shrink-0" aria-hidden="true" />
                            <span class="text-brand-soft font-medium">{{ trans('settings.this_device') }}</span>
                        </template>
                    </div>
                    <div v-if="row.since" class="text-ink-muted text-sm">{{ trans('settings.device_since', { date: row.since }) }}</div>
                    <p v-if="row.device.stale" class="text-danger text-sm">
                        {{ row.current ? trans('settings.stale_this_device') : trans('settings.stale_device') }}
                    </p>
                </div>
                <button type="button" class="text-danger shrink-0 text-sm font-medium hover:underline" @click="remove(row.device, row.current)">
                    {{ trans('settings.remove') }}
                </button>
            </li>
        </ul>

        <p v-if="notice" class="text-ink-muted text-sm">{{ notice }}</p>

        <button
            v-if="offersButton"
            type="button"
            class="bg-brand hover:bg-brand/90 text-on-accent inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold disabled:opacity-50"
            :disabled="busy"
            @click="turnOn"
        >
            {{ busy ? trans('settings.turning_on') : currentRow ? trans('settings.turn_on_again') : trans('settings.turn_on_for_this_device') }}
        </button>
    </div>
</template>
