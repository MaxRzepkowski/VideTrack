<script setup lang="ts">
import { TOOLTIP_ID } from '@/directives/tooltip';
import { hideTooltip, tooltip } from '@/lib/tooltip';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/** Gap between the trigger and the bubble, arrow included. */
const GAP = 8;

/** Keep the bubble this far from the viewport edges. */
const MARGIN = 8;

const bubble = ref<HTMLElement | null>(null);
const above = ref(false);
const left = ref(0);
const top = ref(0);
const arrowLeft = ref(0);

/**
 * Placement is plain arithmetic against the trigger rect: centred, below by
 * default, flipped above when the bottom half of the screen runs out, and
 * clamped sideways so a bubble on the first or last card of a row stays on
 * screen. The arrow then chases the trigger's centre inside the clamped
 * bubble, so it keeps pointing at the button rather than at the bubble.
 */
async function place(): Promise<void> {
    if (!tooltip.value) {
        return;
    }

    await nextTick();

    const el = bubble.value;

    if (!el) {
        return;
    }

    const rect = tooltip.value.rect;
    // `offset*`, not `getBoundingClientRect()`: the enter transition starts on
    // `scale-95`, and a measured box would come back 5% short.
    const { offsetWidth: width, offsetHeight: height } = el;
    const center = rect.left + rect.width / 2;

    above.value = rect.bottom + GAP + height > window.innerHeight - MARGIN && rect.top - GAP - height > MARGIN;
    top.value = above.value ? rect.top - GAP - height : rect.bottom + GAP;
    left.value = Math.min(Math.max(MARGIN, center - width / 2), Math.max(MARGIN, window.innerWidth - width - MARGIN));
    arrowLeft.value = Math.min(Math.max(10, center - left.value), Math.max(10, width - 10));
}

watch(tooltip, place);

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        hideTooltip();
    }
}

function onDocumentPointerDown(event: PointerEvent): void {
    const current = tooltip.value;

    if (!current || (event.target instanceof Node && current.trigger.contains(event.target))) {
        return;
    }

    hideTooltip();
}

onMounted(() => {
    // The rect is captured once, at open time, so anything that moves the
    // trigger under it closes the bubble instead of leaving it stranded.
    window.addEventListener('scroll', hideTooltip, { capture: true, passive: true });
    window.addEventListener('resize', hideTooltip, { passive: true });
    window.addEventListener('keydown', onKeydown);
    document.addEventListener('pointerdown', onDocumentPointerDown);
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', hideTooltip, { capture: true });
    window.removeEventListener('resize', hideTooltip);
    window.removeEventListener('keydown', onKeydown);
    document.removeEventListener('pointerdown', onDocumentPointerDown);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 scale-95"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="tooltip"
                :id="TOOLTIP_ID"
                ref="bubble"
                role="tooltip"
                class="border-line bg-surface-base text-ink pointer-events-none fixed z-[100] rounded-md border px-2.5 py-1.5 text-sm shadow-lg"
                :class="typeof tooltip.content === 'string' ? 'max-w-64 whitespace-pre-line' : 'max-w-72'"
                :style="{ left: `${left}px`, top: `${top}px` }"
            >
                <template v-if="typeof tooltip.content === 'string'">{{ tooltip.content }}</template>
                <!-- Rows: what is measured on the left in quiet ink, the
                     measurement on the right, its own column so the numbers
                     line up under one another instead of drifting with the
                     length of the words in front of them. -->
                <div v-else class="grid grid-cols-[auto_auto] gap-x-6 gap-y-1">
                    <template v-for="row in tooltip.content" :key="row.label">
                        <span class="text-ink-muted">{{ row.label }}</span>
                        <span class="justify-self-end text-right font-semibold">{{ row.value }}</span>
                    </template>
                </div>
                <!-- The arrow is a rotated square, not a triangle: pulled back
                     under the bubble by half its size plus the border, so its
                     own two outer edges continue the frame and its fill hides
                     the seam where it meets the bubble. -->
                <span
                    class="border-line bg-surface-base absolute size-2.5 rounded-[2px]"
                    :class="above ? 'border-r border-b' : 'border-t border-l'"
                    :style="{ left: `${arrowLeft}px`, top: above ? '100%' : '0', transform: 'translate(-50%, -50%) rotate(45deg)' }"
                ></span>
            </div>
        </Transition>
    </Teleport>
</template>
