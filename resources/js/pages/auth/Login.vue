<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import type { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle } from 'lucide-vue-next';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const registrationEnabled = usePage<SharedData>().props.registrationEnabled;

const form = useForm({
    email: '',
    password: '',
    remember: true,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase :title="trans('account.log_in_to_your_account')" :description="trans('account.enter_your_email_and_password_below')">
        <Head :title="trans('account.log_in')" />

        <div v-if="status" class="text-success mb-4 text-center text-sm font-medium">
            {{ status }}
        </div>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">{{ trans('common.email_address') }}</Label>
                    <Input id="email" v-model="form.email" type="email" required autofocus autocomplete="email" placeholder="email@example.com" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">{{ trans('common.password') }}</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm">
                            {{ trans('account.forgot_password_2') }}
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        :placeholder="trans('common.password')"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" v-model:checked="form.remember" />
                        <span>{{ trans('account.remember_me') }}</span>
                    </Label>
                </div>

                <Button type="submit" class="mt-4 w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ trans('account.log_in') }}
                </Button>
            </div>

            <div v-if="registrationEnabled" class="text-ink-muted text-center text-sm">
                {{ trans('account.dont_have_an_account') }}
                <TextLink :href="route('register')">{{ trans('account.sign_up') }}</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
