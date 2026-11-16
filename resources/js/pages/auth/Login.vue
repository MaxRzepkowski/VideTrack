<script setup lang="ts">
import BrandGlyph from '@/components/BrandGlyph.vue';
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
import { LoaderCircle } from '@lucide/vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const page = usePage<SharedData>();
const { registrationEnabled, passwordLogin, identityProviders } = page.props;

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
    <AuthBase
        :title="trans('account.log_in_to_your_account')"
        :description="passwordLogin ? trans('account.enter_your_email_and_password_below') : trans('account.choose_how_to_log_in')"
    >
        <Head :title="trans('account.log_in')" />

        <div v-if="status" class="text-success mb-4 text-center text-sm font-medium">
            {{ status }}
        </div>

        <form v-if="passwordLogin" class="flex flex-col gap-6" @submit.prevent="submit">
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

        <div v-if="identityProviders.length > 0" class="flex flex-col gap-3">
            <div v-if="passwordLogin" class="text-ink-muted flex items-center gap-3 text-sm">
                <span class="bg-line h-px flex-1" aria-hidden="true" />
                {{ trans('account.or_divider') }}
                <span class="bg-line h-px flex-1" aria-hidden="true" />
            </div>

            <!-- Plain links: the trip leaves the app for the provider. -->
            <Button v-for="provider in identityProviders" :key="provider.code" variant="outline" class="w-full" as-child>
                <a :href="route('identities.redirect', { provider: provider.code })">
                    <BrandGlyph :code="provider.glyph" />
                    {{ trans('account.log_in_with_provider', { provider: provider.label }) }}
                </a>
            </Button>

            <InputError :message="page.props.errors.identity" />
        </div>
    </AuthBase>
</template>
