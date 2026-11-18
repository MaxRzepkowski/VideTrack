<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import { withGroup, type WebhookFormValues } from '@/lib/webhooks';
import type { WebhookGroupOption } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

/**
 * A webhook's form, for a new one and a saved one alike. The secret is made
 * by the server and put in by Generate, readable until saved so it can be
 * copied; on a saved webhook the address and secret start empty and an empty
 * one keeps what is saved.
 */
const props = defineProps<{
    idPrefix: string;
    groups: WebhookGroupOption[];
    newSecret: string;
    labelMax: number;
    urlMax: number;
    errors: Partial<Record<string, string>>;
    /** A saved webhook: its address and secret are kept when left empty. */
    saved?: boolean;
}>();

const values = defineModel<WebhookFormValues>({ required: true });

const generated = ref(false);

const order = computed(() => props.groups.map((group) => group.code));

function generate(): void {
    values.value.secret = props.newSecret;
    generated.value = true;
}

function tick(group: WebhookGroupOption, ticked: boolean): void {
    values.value.events = withGroup(values.value.events, group.code, ticked, order.value);
}
</script>

<template>
    <div class="grid gap-2">
        <Label :for="`${props.idPrefix}-url`">{{ trans('settings.webhook_address') }}</Label>
        <Input
            :id="`${props.idPrefix}-url`"
            v-model="values.url"
            type="url"
            :maxlength="props.urlMax"
            :placeholder="props.saved ? trans('settings.saved_leave_empty_to_keep') : trans('settings.webhook_address_placeholder')"
            autocomplete="off"
        />
        <p class="text-ink-muted text-sm">{{ trans('settings.webhook_address_help') }}</p>
        <InputError :message="props.errors.url" />
    </div>

    <div class="grid gap-2">
        <Label :for="`${props.idPrefix}-secret`">{{ trans('settings.signing_secret') }}</Label>
        <div class="flex items-center gap-3">
            <Input
                :id="`${props.idPrefix}-secret`"
                v-model="values.secret"
                :type="generated ? 'text' : 'password'"
                :placeholder="props.saved ? trans('settings.webhook_saved_secret') : undefined"
                autocomplete="off"
            />
            <button type="button" class="text-brand-soft shrink-0 text-sm font-medium hover:underline" @click="generate">
                {{ trans('settings.generate') }}
            </button>
        </div>
        <p class="text-ink-muted text-sm">{{ trans('settings.signing_secret_help', { button: trans('settings.generate') }) }}</p>
        <InputError :message="props.errors.secret" />
    </div>

    <fieldset class="min-w-0 space-y-3">
        <legend class="text-ink mb-2 text-sm font-medium">{{ trans('settings.webhook_events') }}</legend>
        <label v-for="group in props.groups" :key="group.code" class="flex cursor-pointer items-start gap-3">
            <Checkbox :checked="values.events.includes(group.code)" class="mt-0.5" @update:checked="(ticked: boolean) => tick(group, ticked)" />
            <span class="min-w-0">
                <span class="text-ink block text-sm">{{ group.label }}</span>
                <span class="text-ink-muted mt-0.5 block text-sm">{{ group.description }}</span>
            </span>
        </label>
        <InputError :message="props.errors.events" />
    </fieldset>

    <div>
        <Label :for="`${props.idPrefix}-label`" class="text-ink text-sm font-medium">{{ trans('common.name') }}</Label>
        <input
            :id="`${props.idPrefix}-label`"
            v-model="values.label"
            type="text"
            :maxlength="props.labelMax"
            :class="[SETTINGS_FIELD_CLASS, 'mt-1 block w-full']"
        />
        <InputError class="mt-1" :message="props.errors.label" />
    </div>
</template>
