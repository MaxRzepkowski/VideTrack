<script setup lang="ts">
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useConfirmHost } from '@/composables/useConfirm';
import { trans } from 'laravel-vue-i18n';

// The one confirmation dialog for irreversible actions; callers use confirm().
const { pending, answer } = useConfirmHost();
</script>

<template>
    <Dialog :open="pending !== null" @update:open="(open: boolean) => !open && answer(false)">
        <DialogContent v-if="pending" class="border-line-subtle bg-surface max-w-md rounded-2xl p-5 sm:p-7">
            <DialogTitle :class="pending.title ? 'text-2xl font-semibold' : 'text-ink text-base font-semibold'">
                {{ pending.title ?? pending.message }}
            </DialogTitle>
            <DialogDescription v-if="pending.title" class="text-ink-muted m-0 text-base">{{ pending.message }}</DialogDescription>
            <div class="flex justify-end gap-3">
                <button
                    type="button"
                    class="border-line bg-surface-raised text-ink hover:border-line-strong rounded-xl border px-4 py-2.5 text-sm font-semibold transition-colors duration-150"
                    @click="answer(false)"
                >
                    {{ trans('common.cancel') }}
                </button>
                <button
                    type="button"
                    class="bg-danger-strong text-on-accent rounded-xl px-4 py-2.5 text-sm font-bold transition-opacity duration-150 hover:opacity-90"
                    @click="answer(true)"
                >
                    {{ pending.confirmLabel }}
                </button>
            </div>
        </DialogContent>
    </Dialog>
</template>
