<script setup lang="ts">
import { sheetDragCloses } from '@/lib/sheet';
import { DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, VisuallyHidden } from 'radix-vue';
import { computed, ref, watch } from 'vue';

/**
 * A sheet rising from the bottom of a phone's screen, full width, for what a
 * desktop shows as a panel or a window: the thumb reaches it, and it leaves
 * the page above it in view. Closes on a tap outside, on Escape, by the close
 * button its content brings (`DialogClose`), or pulled down by its handle.
 * Its title is for screen readers; the content draws its own heading.
 */
defineProps<{ title: string }>();

const open = defineModel<boolean>('open', { required: true });

// How far the handle has pulled the sheet down; the sheet follows the finger
// with no easing, and eases back when let go short of closing.
const pulled = ref(0);
const pulling = ref(false);
let startY = 0;
let startedAt = 0;
let pointer: number | null = null;

// A sheet that opens again starts where it belongs, not where it was let go.
watch(open, (isOpen) => {
    if (isOpen) {
        pulled.value = 0;
    }
});

// One finger, or the main button, pulls: a second finger or a right click
// moves nothing.
function grab(event: PointerEvent): void {
    if (event.button !== 0 || pointer !== null) {
        return;
    }

    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    pointer = event.pointerId;
    pulling.value = true;
    startY = event.clientY;
    startedAt = performance.now();
}

function pull(event: PointerEvent): void {
    if (event.pointerId === pointer) {
        pulled.value = Math.max(0, event.clientY - startY);
    }
}

function release(event: PointerEvent): void {
    if (event.pointerId !== pointer) {
        return;
    }

    pointer = null;
    pulling.value = false;

    if (sheetDragCloses(pulled.value, performance.now() - startedAt)) {
        open.value = false;
    } else {
        pulled.value = 0;
    }
}

const followFinger = computed(() => ({
    transform: pulled.value > 0 ? `translateY(${pulled.value}px)` : undefined,
    transition: pulling.value ? 'none' : 'transform 200ms ease-out',
}));
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
