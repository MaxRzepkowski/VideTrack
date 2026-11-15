<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { splitAtPlaceholder } from '@/lib/format';
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{
    status?: string;
    sendsMail: boolean;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};

// One sentence for the translator, split where the link goes, so each
// language keeps its own word order around it.
const backToLogin = computed(() => splitAtPlaceholder(trans('account.or_return_to_login'), ':login'));
</script>

<template>
    <AuthLayout :title="trans('account.forgot_password')" :description="trans('account.enter_your_email_to_receive_a')">
        <Head :title="trans('account.forgot_password')" />

        <div v-if="status" class="text-success mb-4 text-center text-sm font-medium">
            {{ status }}
        </div>

        <p v-if="!sendsMail" class="border-line bg-surface text-ink-secondary mb-6 rounded-xl border p-4 text-sm">
            {{ trans('account.this_server_sends_no_mail') }}
        </p>

        <div class="space-y-6">
            <form @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="email">{{ trans('common.email_address') }}</Label>
                    <Input id="email" v-model="form.email" type="email" name="email" autocomplete="email" autofocus placeholder="email@example.com" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="my-6 flex items-center justify-start">
                    <Button class="w-full" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        {{ trans('account.email_password_reset_link') }}
                    </Button>
                </div>
            </form>

            <div class="text-ink-muted text-center text-sm">
                {{ backToLogin.before }}<TextLink :href="route('login')">{{ trans('account.log_in_2') }}</TextLink
                >{{ backToLogin.after }}
            </div>
        </div>
    </AuthLayout>
</template>
