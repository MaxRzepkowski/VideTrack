<script setup lang="ts">
import { DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, VisuallyHidden } from 'radix-vue';

/**
 * A sheet rising from the bottom of a phone's screen, full width, for what a
 * desktop shows as a panel or a window: the thumb reaches it, and it leaves
 * the page above it in view. Closes on a tap outside, on Escape, or by the
 * close button its content brings (`DialogClose`). Its title is for screen
 * readers; the content draws its own heading.
 */
defineProps<{ title: string }>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in data-[state=closed]:fade-out bg-scrim/60 fixed inset-0 z-50"
            />
            <DialogContent
                class="border-line-strong bg-surface text-ink data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:slide-in-from-bottom data-[state=closed]:slide-out-to-bottom fixed inset-x-0 bottom-0 z-50 flex max-h-[90dvh] flex-col rounded-t-2xl border-t pb-6 duration-300"
            >
                <VisuallyHidden>
                    <DialogTitle>{{ title }}</DialogTitle>
                </VisuallyHidden>
                <span class="bg-line-strong mx-auto mt-2 h-1 w-9 shrink-0 rounded-full" aria-hidden="true" />
                <slot />
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
