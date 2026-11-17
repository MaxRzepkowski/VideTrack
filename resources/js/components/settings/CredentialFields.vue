<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CredentialField } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';

/**
 * The fields of a form a module describes (an import source's credentials, a
 * service's sign-in, a notification channel's address), drawn without knowing
 * the module. A checkbox is an opt-in and travels as '1' or ''.
 */
const props = defineProps<{
    fields: CredentialField[];
    idPrefix: string;
    errorOf: (key: string) => string | undefined;
    /** The form changes saved settings: a secret left empty keeps what is saved. */
    keepsSecrets?: boolean;
}>();

const emit = defineEmits<{ edit: [] }>();

const values = defineModel<Record<string, string>>({ required: true });
</script>

<template>
    <template v-for="field in props.fields" :key="field.key">
        <label v-if="field.type === 'checkbox'" class="flex cursor-pointer items-start gap-3">
            <Checkbox
                :checked="values[field.key] === '1'"
                class="mt-0.5"
                @update:checked="
                    (checked: boolean) => {
                        values[field.key] = checked ? '1' : '';
                        emit('edit');
                    }
                "
            />
            <span class="min-w-0">
                <span class="text-ink block text-sm">{{ field.label }}</span>
                <span v-if="field.help" class="text-ink-muted mt-0.5 block text-sm">{{ field.help }}</span>
            </span>
        </label>
        <div v-else class="grid gap-2">
            <Label :for="`${props.idPrefix}-${field.key}`">
                {{ field.label }}
                <span v-if="field.optional" class="text-ink-muted font-normal">{{ trans('common.optional_field') }}</span>
            </Label>
            <Input
                :id="`${props.idPrefix}-${field.key}`"
                v-model="values[field.key]"
                :type="field.type"
                :placeholder="props.keepsSecrets && field.secret ? trans('settings.saved_leave_empty_to_keep') : undefined"
                autocomplete="off"
                @input="emit('edit')"
            />
            <p v-if="field.help" class="text-ink-muted text-sm">{{ field.help }}</p>
            <InputError :message="props.errorOf(field.key)" />
        </div>
    </template>
</template>
