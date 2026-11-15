<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ChevronDown, ChevronsDown, ChevronsUp, ChevronUp } from '@lucide/vue';

// The touch- and keyboard-friendly side of a hand-arranged order: step a card
// up or down, or jump it to either end. Drag & drop does not fire on touch.
defineProps<{ first: boolean; last: boolean }>();

const emit = defineEmits<{ move: [delta: number]; edge: [edge: 'top' | 'bottom'] }>();

const BUTTON =
    'text-ink-secondary hover:text-ink flex size-8 items-center justify-center rounded-md bg-scrim/70 transition-colors duration-150 disabled:opacity-30';
</script>

<template>
    <div class="absolute inset-x-0 bottom-0 flex justify-center gap-1.5 p-2">
        <button
            v-tooltip="trans('lists.move_to_top')"
            :aria-label="trans('lists.move_to_top')"
            type="button"
            :disabled="first"
            :class="BUTTON"
            @click="emit('edge', 'top')"
        >
            <ChevronsUp class="size-4" :stroke-width="2.4" />
        </button>
        <button
            v-tooltip="trans('lists.move_up')"
            :aria-label="trans('lists.move_up')"
            type="button"
            :disabled="first"
            :class="BUTTON"
            @click="emit('move', -1)"
        >
            <ChevronUp class="size-4" :stroke-width="2.4" />
        </button>
        <button
            v-tooltip="trans('lists.move_down')"
            :aria-label="trans('lists.move_down')"
            type="button"
            :disabled="last"
            :class="BUTTON"
            @click="emit('move', 1)"
        >
            <ChevronDown class="size-4" :stroke-width="2.4" />
        </button>
        <button
            v-tooltip="trans('lists.move_to_bottom')"
            :aria-label="trans('lists.move_to_bottom')"
            type="button"
            :disabled="last"
            :class="BUTTON"
            @click="emit('edge', 'bottom')"
        >
            <ChevronsDown class="size-4" :stroke-width="2.4" />
        </button>
    </div>
</template>
