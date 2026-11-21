<script setup lang="ts">
import CredentialFields from '@/components/settings/CredentialFields.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { confirm } from '@/composables/useConfirm';
import { formatDateTime, relativeTime } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import type { ConnectAttemptStatus } from '@/types/generated';
import type { ConnectDef, ConnectionCard } from '@/types/integrations';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Connecting the account an import source reads through, and what is held
 * once it is connected. Everything this card asks while it waits (the device
 * code, its approval, a key check) goes through plain fetch() against JSON
 * endpoints — never router.*, whose redirect would repaint the import page
 * under the user. Only their own clicks (save, disconnect) go through Inertia.
 */
const props = defineProps<{ connect: ConnectDef }>();
const connection = defineModel<ConnectionCard | null>('connection', { required: true });

/** A refused call: a message, or validation errors keyed by field. */
interface Refusal {
    message?: string;
    errors?: Record<string, string[]>;
}

type Answer<T> = { ok: true; data: T } | { ok: false; data: Refusal };

async function call<T>(method: 'GET' | 'POST' | 'DELETE', url: string, body?: unknown): Promise<Answer<T>> {
    const response = await jsonFetch(url, { method, body });

    const data: unknown = (await response.json().catch(() => ({}))) ?? {};

    return response.ok ? { ok: true, data: data as T } : { ok: false, data: data as Refusal };
}

const needsConnecting = computed(() => connection.value === null || connection.value.status === 'expired');
// A trip to the service's site that came back refused says why on the page it lands on.
const failure = ref<string | null>(props.connect.method === 'redirect' ? (usePage().props.errors?.[props.connect.errorKey] ?? null) : null);
const busy = ref(false);

// Device flow: the code to type and until when it holds. The page only asks
// "approved yet?" on the service's interval; the server keeps the real pace.
const device = ref<{ userCode: string; url: string; expiresAt: number; interval: number } | null>(null);
const remaining = ref(0);
let pollTimer: number | undefined;
let clockTimer: number | undefined;
// Which attempt is current: an answer to a poll of an attempt the user has
// since cancelled or started over is about a code no longer on screen.
let attempt = 0;

function stopDevice(): void {
    attempt++;
    window.clearTimeout(pollTimer);
    window.clearInterval(clockTimer);
    device.value = null;
}

/** What the server said a refusal was, under the card's own field first. */
function refusal(data: Refusal, fallback: string): string {
    return data.errors?.connection?.[0] ?? data.message ?? fallback;
}

async function beginDevice(): Promise<void> {
    failure.value = null;
    busy.value = true;

    try {
        const { ok, data } = await call<{
            userCode: string;
            verificationUri: string;
            verificationUriComplete: string | null;
            expiresIn: number;
            interval: number;
        }>('POST', route('settings.connections.device.begin', { service: props.connect.service }));

        if (!ok) {
            failure.value = refusal(data, trans('import.the_service_could_not_start_the'));

            return;
        }

        attempt++;
        device.value = {
            userCode: data.userCode,
            url: data.verificationUriComplete ?? data.verificationUri,
            expiresAt: Date.now() + data.expiresIn * 1000,
            interval: data.interval,
        };
        tick();
        clockTimer = window.setInterval(tick, 1000);
        schedulePoll();
    } finally {
        busy.value = false;
    }
}

function tick(): void {
    remaining.value = device.value ? Math.max(0, Math.round((device.value.expiresAt - Date.now()) / 1000)) : 0;
}

function schedulePoll(): void {
    if (device.value) {
        pollTimer = window.setTimeout(poll, device.value.interval * 1000);
    }
}

type PollAnswer =
    | { status: Exclude<ConnectAttemptStatus, 'connected' | 'failed'> }
    | { status: Extract<ConnectAttemptStatus, 'connected'>; connection: ConnectionCard }
    | { status: Extract<ConnectAttemptStatus, 'failed'>; message: string };

async function poll(): Promise<void> {
    const asked = attempt;
    const answer = await call<PollAnswer>('POST', route('settings.connections.device.poll', { service: props.connect.service })).catch(() => null);
    // A refused or lost poll is just another "not yet".
    const data: PollAnswer = answer?.ok ? answer.data : { status: 'pending' };

    if (!device.value || asked !== attempt) {
        return;
    }

    if (data.status === 'connected') {
        stopDevice();
        connection.value = data.connection;
    } else if (data.status === 'expired') {
        stopDevice();
        failure.value = trans('import.the_code_expired_start_again');
    } else if (data.status === 'failed') {
        stopDevice();
        failure.value = data.message;
    } else {
        schedulePoll();
    }
}

async function cancelDevice(): Promise<void> {
    stopDevice();
    await call('DELETE', route('settings.connections.device.cancel', { service: props.connect.service })).catch(() => undefined);
}

onBeforeUnmount(stopDevice);

// A trip to the service's site: what the way back needs stays on the server,
// the page only follows the address it is given and lands here again.
async function beginRedirect(): Promise<void> {
    failure.value = null;
    busy.value = true;

    const answer = await call<{ url: string }>('POST', route('settings.connections.redirect.begin', { service: props.connect.service })).catch(
        () => null,
    );

    if (!answer?.ok) {
        failure.value = answer
            ? refusal(answer.data, trans('import.the_service_could_not_start_the'))
            : trans('import.the_service_could_not_start_the');
        busy.value = false;

        return;
    }

    window.location.assign(answer.data.url);
}

const countdown = computed(() => {
    const minutes = Math.floor(remaining.value / 60);
    const seconds = String(remaining.value % 60).padStart(2, '0');

    return `${minutes}:${seconds}`;
});

// Pasted address and key: checked once, then the account to be picked.
const values = ref<Record<string, string>>({});
const fieldErrors = ref<Record<string, string>>({});
const choices = ref<{ id: string; label: string }[] | null>(null);
const choice = ref('');

function readErrors(data: Refusal): void {
    const errors = data.errors ?? {};

    failure.value = errors.connection?.[0] ?? null;
    fieldErrors.value = Object.fromEntries(
        Object.entries(errors)
            .filter(([key]) => key.startsWith('values.'))
            .flatMap(([key, [message]]) => (message === undefined ? [] : [[key.slice(7), message]])),
    );
}

async function verify(): Promise<void> {
    failure.value = null;
    fieldErrors.value = {};
    choices.value = null;
    busy.value = true;

    try {
        const { ok, data } = await call<{ choices: { id: string; label: string }[] }>(
            'POST',
            route('settings.connections.verify', { service: props.connect.service }),
            { values: values.value },
        );

        if (!ok) {
            readErrors(data);

            return;
        }

        choices.value = data.choices;
        choice.value = data.choices.length === 1 ? (data.choices[0]?.id ?? '') : '';
    } finally {
        busy.value = false;
    }
}

async function connectByForm(): Promise<void> {
    failure.value = null;
    busy.value = true;

    try {
        const { ok, data } = await call<{ connection: ConnectionCard }>(
            'POST',
            route('settings.connections.connect', { service: props.connect.service }),
            {
                values: values.value,
                choice: choice.value || null,
            },
        );

        if (!ok) {
            readErrors(data);

            return;
        }

        connection.value = data.connection;
        values.value = {};
        choices.value = null;
    } finally {
        busy.value = false;
    }
}

// Auto-import follows the settings rule: an explicit save.
const settings = useForm({ auto_import: connection.value?.autoImport ?? false });

watch(
    () => connection.value?.autoImport,
    (autoImport) => {
        settings.defaults({ auto_import: autoImport ?? false });
        settings.reset();
    },
);

function save(): void {
    if (!connection.value) {
        return;
    }

    settings.patch(route('settings.connections.update', { connection: connection.value.id }), {
        preserveScroll: true,
        onSuccess: () => settings.defaults(),
    });
}

async function disconnect(): Promise<void> {
    const held = connection.value;

    if (held === null) {
        return;
    }

    const confirmed = await confirm({
        message: trans('import.disconnect_service_nothing_imported_so_far', { service: props.connect.name }),
        confirmLabel: trans('common.disconnect'),
    });

    if (!confirmed) {
        return;
    }

    router.delete(route('settings.connections.destroy', { connection: held.id }), { preserveScroll: true });
}

const pace = computed(() => (props.connect.autoImportMinutes >= 1440 ? trans('common.daily') : trans('common.hourly')));

function displayUrl(url: string): string {
    return url.replace(/^https?:\/\//, '');
}
</script>

<template>
    <div class="space-y-4">
        <template v-if="connection && !needsConnecting">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <p class="text-ink text-sm">{{ trans('import.connected_as_account', { account: connection.account ?? connect.name }) }}</p>
                <button type="button" class="text-danger text-sm font-medium hover:underline" @click="disconnect">
                    {{ trans('common.disconnect') }}
                </button>
            </div>

            <label class="flex items-center justify-between gap-3">
                <span class="min-w-0">
                    <span class="text-ink block text-sm">{{ trans('import.import_automatically') }}</span>
                    <span class="text-ink-muted block text-sm">{{ pace }}</span>
                </span>
                <ToggleSwitch v-model="settings.auto_import" />
            </label>

            <p v-if="connection.lastImportedAt" v-tooltip="formatDateTime(connection.lastImportedAt)" class="text-ink-muted text-sm">
                {{ trans('import.last_import_time', { time: relativeTime(connection.lastImportedAt) ?? '' }) }}
            </p>
            <!-- Written by a background run, in the instance's language rather than
                 the viewer's: a known message is translated here, anything else
                 (an upstream error) shows as it came. -->
            <p v-if="connection.lastError" class="text-danger text-sm">{{ connection.lastError }}</p>

            <SettingsSaveBar placement="card" :dirty="settings.isDirty" :processing="settings.processing" @save="save" @discard="settings.reset()" />
        </template>

        <template v-else>
            <p v-if="connection?.status === 'expired'" class="text-danger text-sm">
                {{ trans('import.this_connection_stopped_working_connect_again') }}
            </p>

            <template v-if="connect.method === 'device'">
                <div v-if="device" class="space-y-3">
                    <p class="text-ink text-sm">{{ trans('import.open_the_link_and_approve_this', { service: connect.name }) }}</p>
                    <p class="text-ink font-mono text-2xl font-semibold tracking-widest">{{ device.userCode }}</p>
                    <a
                        :href="device.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-brand-soft text-sm font-medium break-all hover:underline"
                    >
                        {{ displayUrl(device.url) }}
                    </a>
                    <p class="text-ink-muted text-sm" role="status" aria-live="polite">
                        {{ trans('import.waiting_for_approval_the_code_is', { time: countdown }) }}
                    </p>
                    <button type="button" class="text-brand-soft text-sm font-medium hover:underline" @click="cancelDevice">
                        {{ trans('common.cancel') }}
                    </button>
                </div>
                <Button v-else type="button" class="h-10 py-2" :disabled="busy" @click="beginDevice">
                    {{ trans('import.connect_service', { service: connect.name }) }}
                </Button>
            </template>

            <Button v-else-if="connect.method === 'redirect'" type="button" class="h-10 py-2" :disabled="busy" @click="beginRedirect">
                {{ trans('import.connect_service', { service: connect.name }) }}
            </Button>

            <form v-else class="space-y-4" @submit.prevent="choices ? connectByForm() : verify()">
                <CredentialFields
                    v-model="values"
                    :fields="connect.fields"
                    :id-prefix="`connect-${connect.service}`"
                    :error-of="(key) => fieldErrors[key]"
                    @edit="choices = null"
                />

                <div v-if="choices" class="grid gap-2">
                    <Label :for="`connect-${connect.service}-choice`">{{ trans('import.which_user_are_you') }}</Label>
                    <select :id="`connect-${connect.service}-choice`" v-model="choice" :class="[SETTINGS_FIELD_CLASS, 'text-ink w-full']">
                        <option value="" disabled>{{ trans('import.pick_one') }}</option>
                        <option v-for="option in choices" :key="option.id" :value="option.id">{{ option.label }}</option>
                    </select>
                </div>

                <Button type="submit" class="h-10 py-2" :disabled="busy || (choices !== null && choice === '')">
                    {{ choices ? trans('common.connect') : trans('import.check') }}
                </Button>
            </form>

            <p v-if="failure" class="text-danger text-sm">{{ failure }}</p>
        </template>
    </div>
</template>
