<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ChannelKindDialog from '@/components/settings/channels/ChannelKindDialog.vue';
import ChannelLogo from '@/components/settings/channels/ChannelLogo.vue';
import CredentialFields from '@/components/settings/CredentialFields.vue';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { blankConfig, channelModes, configError } from '@/lib/channels';
import { SETTINGS_CARD_CLASS, SETTINGS_FIELD_CLASS } from '@/lib/ui';
import type { ChannelKindData, ChannelMode } from '@/types/generated';
import { useForm } from '@inertiajs/vue3';
import { ChevronRight, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * A new channel: a block that opens the services, the picked one taking its
 * place (a click picks another), and below it what that service asks for.
 */
const props = defineProps<{ kinds: ChannelKindData[]; labelMax: number; digestHour: number; defaultMode: ChannelMode }>();

const picked = ref<ChannelKindData | null>(null);
const choosing = ref(false);

const form = useForm({
    driver: '',
    kind: '',
    label: '',
    mode: props.defaultMode,
    config: {} as Record<string, string>,
});

useUnsavedChanges(() => picked.value !== null && form.isDirty);

function pick(kind: ChannelKindData): void {
    picked.value = kind;
    form.clearErrors();
    form.driver = kind.driver;
    form.kind = kind.code;
    form.config = blankConfig(kind.fields);
}

function startOver(): void {
    picked.value = null;
    form.reset();
    form.clearErrors();
}

function add(): void {
    form.post(route('settings.channels.store'), {
        preserveScroll: true,
        onSuccess: () => {
            startOver();
            toast.success(trans('settings.channel_added'));
        },
    });
}
</script>

<template>
    <div :class="[SETTINGS_CARD_CLASS, 'space-y-4']">
        <div class="text-ink text-base font-semibold">{{ trans('settings.add_a_channel') }}</div>

        <button
            type="button"
            class="border-line hover:border-brand focus-visible:border-brand flex w-full items-center gap-4 rounded-xl border border-dashed p-4 text-left transition-colors duration-150"
            :aria-label="picked ? trans('settings.change_the_service', { service: picked.label }) : undefined"
            @click="choosing = true"
        >
            <template v-if="picked">
                <ChannelLogo :kind="picked.code" class="h-10 max-w-32" />
                <span class="text-ink min-w-0 flex-1 truncate text-base font-semibold">{{ picked.label }}</span>
                <span class="text-ink-muted shrink-0 text-sm">{{ trans('settings.change') }}</span>
            </template>
            <template v-else>
                <span class="bg-surface-raised text-ink-muted inline-flex size-12 shrink-0 items-center justify-center rounded-xl">
                    <Plus class="size-6" aria-hidden="true" />
                </span>
                <span class="text-ink min-w-0 flex-1 text-base font-semibold">{{ trans('settings.choose_a_service') }}</span>
            </template>
            <ChevronRight class="text-ink-muted size-5 shrink-0" aria-hidden="true" />
        </button>

        <ChannelKindDialog v-model:open="choosing" :kinds="props.kinds" @pick="pick" />

        <form v-if="picked" class="space-y-4" @submit.prevent="add">
            <a
                v-if="picked.setupUrl"
                :href="picked.setupUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="text-brand-soft inline-block text-sm font-medium hover:underline"
            >
                {{ trans('common.how_to_set_it_up') }}
            </a>

            <fieldset :disabled="form.processing" class="min-w-0 space-y-4">
                <CredentialFields
                    v-model="form.config"
                    :fields="picked.fields"
                    id-prefix="new-channel"
                    :error-of="(key) => configError(form.errors, key)"
                />
                <InputError :message="form.errors.config" />

                <div>
                    <Label for="new-channel-label" class="text-ink text-sm font-medium">{{ trans('common.name') }}</Label>
                    <input
                        id="new-channel-label"
                        v-model="form.label"
                        type="text"
                        :maxlength="props.labelMax"
                        :placeholder="picked.label"
                        :class="[SETTINGS_FIELD_CLASS, 'mt-1 block w-full']"
                    />
                    <InputError class="mt-1" :message="form.errors.label" />
                </div>

                <div>
                    <Label for="new-channel-mode" class="text-ink text-sm font-medium">{{ trans('settings.delivery') }}</Label>
                    <select id="new-channel-mode" v-model="form.mode" :class="[SETTINGS_FIELD_CLASS, 'mt-1 block w-full']">
                        <option v-for="choice in channelModes(props.digestHour)" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                    </select>
                    <InputError class="mt-1" :message="form.errors.mode" />
                </div>
            </fieldset>

            <button
                type="submit"
                class="bg-brand hover:bg-brand/90 text-on-accent inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold disabled:opacity-50"
                :disabled="form.processing"
            >
                {{ trans('settings.add_channel') }}
            </button>
        </form>
    </div>
</template>
