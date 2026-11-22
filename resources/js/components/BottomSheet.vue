<script setup lang="ts">
import SheetHandle from '@/components/modal/SheetHandle.vue';
import { useSheetDrag } from '@/composables/useSheetDrag';
import { DIALOG_OVERLAY_CLASS } from '@/lib/ui';
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

const drag = useSheetDrag(open);
const { followFinger } = drag;
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay :class="[DIALOG_OVERLAY_CLASS, 'z-50']" />
            <DialogContent
                class="border-line-strong bg-surface text-ink data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:slide-in-from-bottom data-[state=closed]:slide-out-to-bottom fixed inset-x-2 bottom-0 z-50 flex max-h-[90dvh] flex-col rounded-t-2xl border-x border-t pb-6 duration-300"
                :style="followFinger"
            >
                <VisuallyHidden>
                    <DialogTitle>{{ title }}</DialogTitle>
                </VisuallyHidden>
                <SheetHandle :drag="drag" />
                <slot />
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
