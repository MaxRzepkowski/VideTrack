<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle } from '@lucide/vue';

const form = useForm({
    name: '',
    username: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthBase :title="trans('account.create_an_account')" :description="trans('account.enter_your_details_below_to_create')">
        <Head :title="trans('account.register')" />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">{{ trans('common.name') }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        :placeholder="trans('account.full_name')"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="username">{{ trans('common.username') }}</Label>
                    <Input
                        id="username"
                        v-model="form.username"
                        type="text"
                        required
                        autocomplete="username"
                        :placeholder="trans('account.username_a_z_0_9')"
                    />
                    <InputError :message="form.errors.username" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ trans('common.email_address') }}</Label>
                    <Input id="email" v-model="form.email" type="email" required autocomplete="email" placeholder="email@example.com" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{ trans('common.password') }}</Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="trans('common.password')"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{ trans('common.confirm_password') }}</Label>
                    <Input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="trans('common.confirm_password')"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-2 w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ trans('account.create_account') }}
                </Button>
            </div>

            <div class="text-ink-muted text-center text-sm">
                {{ trans('account.already_have_an_account') }}
                <TextLink :href="route('login')" class="underline underline-offset-4">{{ trans('account.log_in') }}</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
