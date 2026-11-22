<script setup lang="ts">
import SheetHandle from '@/components/modal/SheetHandle.vue';
import { useSheetDrag } from '@/composables/useSheetDrag';
import { DIALOG_OVERLAY_CLASS } from '@/lib/ui';
import { trans } from 'laravel-vue-i18n';
import { X } from '@lucide/vue';
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue';

/**
 * The app's dialog: centred on a desktop, a sheet rising from the bottom on a
 * phone (pulled down by its handle to close), where the thumb reaches it. The
 * heading is the title, a line of facts under it (`meta`) and the close
 * button; the body comes in the default slot. A confirmation is narrower.
 */
withDefaults(defineProps<{ title: string; size?: 'base' | 'confirm' }>(), { size: 'base' });

const open = defineModel<boolean>('open', { required: true });

const drag = useSheetDrag(open);
const { followFinger } = drag;

const WIDTH = {
    base: 'md:w-110',
    confirm: 'md:w-100',
};
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay :class="DIALOG_OVERLAY_CLASS" />
            <DialogContent
                v-bind="$slots.meta ? {} : { 'aria-describedby': undefined }"
                class="border-line-strong bg-surface text-ink shadow-scrim/60 data-[state=open]:animate-in data-[state=closed]:animate-out max-md:data-[state=open]:slide-in-from-bottom max-md:data-[state=closed]:slide-out-to-bottom md:data-[state=open]:fade-in md:data-[state=open]:zoom-in-95 md:data-[state=closed]:fade-out md:data-[state=closed]:zoom-out-95 fixed z-50 flex max-h-[90dvh] flex-col border shadow-[0_8px_32px] max-md:inset-x-2 max-md:bottom-0 max-md:rounded-t-2xl max-md:border-b-0 max-md:pb-6 max-md:duration-300 md:top-1/2 md:left-1/2 md:max-w-[calc(100vw-2rem)] md:-translate-x-1/2 md:-translate-y-1/2 md:rounded-xl md:pb-1 md:duration-200"
                :class="WIDTH[size]"
                :style="followFinger"
            >
                <SheetHandle :drag="drag" class="md:hidden" />

                <div class="flex items-start gap-3 pt-1 pr-4 pb-3.5 pl-5 md:pt-4.5">
                    <div class="min-w-0 flex-1">
                        <DialogTitle class="text-ink text-xl leading-7 font-semibold">{{ title }}</DialogTitle>
                        <DialogDescription v-if="$slots.meta" as="div" class="mt-0.5 flex items-center gap-2 text-sm">
                            <slot name="meta" />
                        </DialogDescription>
                    </div>
                    <DialogClose
                        :aria-label="trans('common.close')"
                        class="bg-surface-raised text-ink-muted hover:text-ink flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg transition-colors duration-150"
                    >
                        <X class="size-4.5" />
                    </DialogClose>
                </div>

                <div class="scroll-subtle min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    <slot />
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
