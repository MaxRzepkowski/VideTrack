<script setup lang="ts">
import AppModal from '@/components/modal/AppModal.vue';
import { useConfirmHost } from '@/composables/useConfirm';
import { trans } from 'laravel-vue-i18n';

// The one confirmation dialog for irreversible actions; callers use confirm().
const { pending, answer } = useConfirmHost();

const BUTTON = 'flex h-11 cursor-pointer items-center justify-center rounded-[10px] px-4.5 text-base font-semibold transition-colors duration-150';
</script>

<template>
    <AppModal
        :open="pending !== null"
        :title="pending ? (pending.title ?? pending.message) : ''"
        size="confirm"
        @update:open="(open: boolean) => !open && answer(false)"
    >
        <template v-if="pending?.title" #meta>
            <span class="text-ink-muted">{{ pending.message }}</span>
        </template>

        <!-- Side by side on a desktop; on a phone stacked, the action on top
             where the thumb is. -->
        <div v-if="pending" class="flex flex-col-reverse gap-2 px-5 pt-1 pb-4 md:flex-row">
            <button type="button" :class="BUTTON" class="bg-surface-overlay text-ink hover:bg-line-strong" @click="answer(false)">
                {{ trans('common.cancel') }}
            </button>
            <button type="button" :class="BUTTON" class="bg-remove text-on-remove hover:opacity-90" @click="answer(true)">
                {{ pending.confirmLabel }}
            </button>
        </div>
    </AppModal>
</template>
