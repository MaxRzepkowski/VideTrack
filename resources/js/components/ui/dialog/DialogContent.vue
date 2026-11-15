<script setup lang="ts">
import { cn } from '@/lib/utils';
import { X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    useForwardPropsEmits,
    type DialogContentEmits,
    type DialogContentProps,
} from 'radix-vue';
import { computed, useAttrs, type HTMLAttributes } from 'vue';
import { trans } from 'laravel-vue-i18n';

// The root here is a fragment (portal + overlay + content), so Vue drops
// fallthrough attributes instead of inheriting them — `style` and the rest
// would silently never reach the dialog, so they are forwarded by hand.
defineOptions({ inheritAttrs: false });

/**
 * `hideClose` drops the corner X. For a dialog whose whole body is one focused
 * control — the command palette's input — the button is a tab stop that leads
 * nowhere Esc does not, and it sits on top of the first row.
 */
const props = defineProps<DialogContentProps & { class?: HTMLAttributes['class']; hideClose?: boolean }>();
const emits = defineEmits<DialogContentEmits>();

const attrs: Record<string, unknown> = useAttrs();

const delegatedProps = computed(() => {
    const { class: _, hideClose: __, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardPropsEmits(delegatedProps, emits);

/**
 * Radix points `aria-describedby` at an id it mints for `DialogDescription`
 * and warns when nothing renders under it — which is every dialog here, none
 * of them carry one. Saying "there is no description" is the documented
 * answer; a dialog that grows a `<DialogDescription>` has to pass its id in
 * explicitly, or the two will not be wired together.
 */
const describedBy = computed(() => attrs['aria-describedby'] as string | undefined);
</script>

<template>
    <DialogPortal>
        <DialogOverlay
            class="fixed inset-0 z-50 bg-scrim/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
        />
        <DialogContent
            v-bind="{ ...forwarded, ...attrs }"
            :aria-describedby="describedBy"
            :class="
                cn(
                    'fixed left-1/2 top-1/2 z-50 grid max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 gap-4 overflow-y-auto rounded-lg border bg-surface-base p-6 shadow-lg duration-200 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
                    props.class,
                )
            "
        >
            <slot />

            <DialogClose
                v-if="!hideClose"
                class="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-surface-base transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 disabled:pointer-events-none data-[state=open]:bg-surface-overlay data-[state=open]:text-ink-muted"
            >
                <X class="h-4 w-4" />
                <span class="sr-only">{{ trans('common.close') }}</span>
            </DialogClose>
        </DialogContent>
    </DialogPortal>
</template>
