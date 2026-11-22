<script setup lang="ts">
import type { useSheetDrag } from '@/composables/useSheetDrag';

/**
 * The grip at the top of a sheet, which pulls it down to close. A pointer's way
 * only: Escape, a tap outside and the close button stay for everyone else, so
 * it is hidden from assistive technology.
 */
defineProps<{ drag: ReturnType<typeof useSheetDrag> }>();
</script>

<template>
    <div
        class="flex h-6 shrink-0 cursor-grab touch-none items-center justify-center active:cursor-grabbing"
        aria-hidden="true"
        @pointerdown="drag.grab"
        @pointermove="drag.pull"
        @pointerup="drag.release"
        @pointercancel="drag.release"
    >
        <span class="bg-line-strong h-1 w-9 rounded-full" />
    </div>
</template>
