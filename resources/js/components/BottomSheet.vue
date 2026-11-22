<script setup lang="ts">
import { useSheetDrag } from '@/composables/useSheetDrag';
import { DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, VisuallyHidden } from 'radix-vue';

/**
 * A sheet rising from the bottom of a phone's screen, full width, for what a
 * desktop shows as a panel or a window: the thumb reaches it, and it leaves
 * the page above it in view. Closes on a tap outside, on Escape, by the close
 * button its content brings (`DialogClose`), or pulled down by its handle.
 * Its title is for screen readers; the content draws its own heading.
 */
defineProps<{ title: string }>();

const open = defineModel<boolean>('open', { required: true });

const { grab, pull, release, followFinger } = useSheetDrag(open);
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in data-[state=closed]:fade-out bg-scrim/60 fixed inset-0 z-50"
            />
            <DialogContent
                class="border-line-strong bg-surface text-ink data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:slide-in-from-bottom data-[state=closed]:slide-out-to-bottom fixed inset-x-2 bottom-0 z-50 flex max-h-[90dvh] flex-col rounded-t-2xl border-x border-t pb-6 duration-300"
                :style="followFinger"
            >
                <VisuallyHidden>
                    <DialogTitle>{{ title }}</DialogTitle>
                </VisuallyHidden>
                <!-- The handle is a pointer's way to close; Escape, a tap outside
                     and the close button stay for everyone else. -->
                <div
                    class="flex h-6 shrink-0 cursor-grab touch-none items-center justify-center active:cursor-grabbing"
                    aria-hidden="true"
                    @pointerdown="grab"
                    @pointermove="pull"
                    @pointerup="release"
                    @pointercancel="release"
                >
                    <span class="bg-line-strong h-1 w-9 rounded-full" />
                </div>
                <slot />
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
