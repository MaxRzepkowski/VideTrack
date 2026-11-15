<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// Template ref holds the Input COMPONENT instance; the native element to
// focus is its single root ($el).
const passwordInput = ref<InstanceType<typeof Input> | null>(null);

const open = ref(false);

const form = useForm({
    password: '',
});

const onOpenChange = (value: boolean) => {
    open.value = value;

    if (!value) {
        form.clearErrors();
        form.reset();
    }
};

const clearData = (e: Event) => {
    e.preventDefault();

    form.delete(route('settings.data.destroy'), {
        preserveScroll: true,
        onSuccess: () => onOpenChange(false),
        onError: () => (passwordInput.value?.$el as HTMLInputElement | undefined)?.focus(),
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <div class="space-y-6">
        <HeadingSmall :title="trans('settings.clear_all_data')" :description="trans('settings.remove_your_watch_history_ratings_favorites')" />
        <div class="border-danger-line bg-danger-surface flex flex-col gap-4 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-danger relative min-w-0 space-y-0.5">
                <p class="font-medium">{{ trans('settings.warning') }}</p>
                <p class="text-sm">{{ trans('settings.your_account_stays_but_all_tracked') }}</p>
            </div>
            <Dialog :open="open" @update:open="onOpenChange">
                <DialogTrigger as-child>
                    <Button variant="destructive" class="h-10 py-2">{{ trans('settings.clear_all_data') }}</Button>
                </DialogTrigger>
                <DialogContent>
                    <form class="space-y-6" @submit="clearData">
                        <DialogHeader class="space-y-3">
                            <DialogTitle>{{ trans('settings.are_you_sure_you_want_to') }}</DialogTitle>
                            <DialogDescription>
                                {{ trans('settings.your_entire_watch_history_ratings_favorites') }}
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="clear-data-password" class="sr-only">{{ trans('common.password') }}</Label>
                            <Input
                                id="clear-data-password"
                                ref="passwordInput"
                                v-model="form.password"
                                type="password"
                                name="password"
                                :placeholder="trans('common.password')"
                            />
                            <InputError :message="form.errors.password" />
                        </div>

                        <DialogFooter>
                            <DialogClose as-child>
                                <Button type="button" variant="secondary" class="h-10 py-2">{{ trans('common.cancel') }}</Button>
                            </DialogClose>

                            <Button type="submit" variant="destructive" class="h-10 py-2" :disabled="form.processing">
                                {{ trans('settings.clear_all_data') }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
        <p v-if="form.recentlySuccessful" class="text-action-watched text-sm">{{ trans('settings.all_data_cleared') }}</p>
    </div>
</template>
