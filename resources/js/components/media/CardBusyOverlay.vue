<script setup lang="ts">
import { useCardBusy } from '@/composables/useMediaActions';
import type { MediaSummary } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle } from '@lucide/vue';

// Veils a card's poster / still while a write for that item is on its way to
// the server, and until the loader's minimum is served out. Everything the card
// shows is held at its pre-write value underneath (see `frozen`), so the change
// arrives with the veil lifting rather than racing it.
//
// Goes last inside the card's `relative` image box (it sits above the badges),
// and lets clicks through: the poster is still a link to the item.
const props = defineProps<{ media: MediaSummary }>();

const busy = useCardBusy(() => props.media);
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-150"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-200"
        leave-to-class="opacity-0"
    >
        <div
            v-if="busy"
            class="bg-scrim/40 pointer-events-none absolute inset-0 z-30 flex items-center justify-center backdrop-blur-sm"
            role="status"
            :aria-label="trans('common.saving')"
        >
            <LoaderCircle class="text-ink size-9 animate-spin" :stroke-width="2.2" />
        </div>
    </Transition>
</template>
