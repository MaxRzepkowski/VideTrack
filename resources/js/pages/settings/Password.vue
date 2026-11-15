<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { SETTINGS_CARD_CLASS, SETTINGS_FIELD_CLASS } from '@/lib/ui';
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

const passwordInput = ref<HTMLInputElement | null>(null);
const currentPasswordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

useUnsavedChanges(() => form.isDirty);

function discard(): void {
    form.reset();
    form.clearErrors();
}

function submit(): void {
    if (!form.isDirty || form.processing) {
        return;
    }

    form.put(route('settings.password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            toast.success(trans('settings.saved_2'));
        },
        // A refused password is cleared and its field takes the caret, so the
        // next attempt starts from an empty box.
        onError: (errors) => {
            if (errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }

            if (errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.password')" />

        <SettingsLayout>
            <form class="space-y-6" @submit.prevent="submit">
                <fieldset :disabled="form.processing" class="min-w-0 space-y-6">
                    <HeadingSmall :title="trans('settings.update_password')" :description="trans('settings.ensure_your_account_is_using_a')" />

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="current_password" class="text-ink text-base font-semibold">{{ trans('settings.current_password') }}</Label>
                        <input
                            id="current_password"
                            ref="currentPasswordInput"
                            v-model="form.current_password"
                            type="password"
                            autocomplete="current-password"
                            :class="[SETTINGS_FIELD_CLASS, 'mt-3 block w-full']"
                        />
                        <InputError class="mt-2" :message="form.errors.current_password" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="password" class="text-ink text-base font-semibold">{{ trans('settings.new_password') }}</Label>
                        <input
                            id="password"
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            :class="[SETTINGS_FIELD_CLASS, 'mt-3 block w-full']"
                        />
                        <InputError class="mt-2" :message="form.errors.password" />

                        <Label for="password_confirmation" class="text-ink mt-5 block text-base font-semibold">{{
                            trans('common.confirm_password')
                        }}</Label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            :class="[SETTINGS_FIELD_CLASS, 'mt-3 block w-full']"
                        />
                        <InputError class="mt-2" :message="form.errors.password_confirmation" />
                    </div>
                </fieldset>
                <SettingsSaveBar :dirty="form.isDirty" :processing="form.processing" @save="submit" @discard="discard" />
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
