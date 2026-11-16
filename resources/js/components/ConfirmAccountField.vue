<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { signedInUser } from '@/lib/pageContext';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

/**
 * What confirms an action that cannot be undone: the password where it is
 * the viewer's way in, otherwise their username typed out.
 */
const props = defineProps<{
    idPrefix: string;
    errors: { password?: string; username?: string };
}>();

const password = defineModel<string>('password', { required: true });
const username = defineModel<string>('username', { required: true });

const viewer = signedInUser();

// The Input component's instance; the native element to focus is its root.
const input = ref<InstanceType<typeof Input> | null>(null);

defineExpose({ focus: () => (input.value?.$el as HTMLInputElement | undefined)?.focus() });
</script>

<template>
    <div v-if="viewer.password_sign_in" class="grid gap-2">
        <Label :for="`${props.idPrefix}-password`" class="sr-only">{{ trans('common.password') }}</Label>
        <Input
            :id="`${props.idPrefix}-password`"
            ref="input"
            v-model="password"
            type="password"
            name="password"
            :placeholder="trans('common.password')"
        />
        <InputError :message="props.errors.password" />
    </div>
    <div v-else class="grid gap-2">
        <Label :for="`${props.idPrefix}-username`">{{ trans('settings.type_your_username_to_confirm', { username: viewer.username }) }}</Label>
        <Input
            :id="`${props.idPrefix}-username`"
            ref="input"
            v-model="username"
            type="text"
            name="username"
            autocomplete="off"
            autocapitalize="none"
            spellcheck="false"
        />
        <InputError :message="props.errors.username" />
    </div>
</template>
