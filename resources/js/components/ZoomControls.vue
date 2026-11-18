<script setup lang="ts">
import { Maximize, Minus, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';

/** The zoom buttons over a zoomable drawing (see useZoom): in, out, and back to the whole. */
defineProps<{
    scale: number;
    max: number;
}>();

defineEmits<{ in: []; out: []; reset: [] }>();

const BUTTON =
    'border-line-subtle bg-surface text-ink-muted enabled:hover:border-brand enabled:hover:text-ink focus-visible:ring-brand focus-visible:ring-offset-surface-base grid size-9 place-items-center rounded-full border transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:opacity-35';
</script>

<template>
    <div class="flex items-center gap-2">
        <button
            v-tooltip="trans('common.zoom_in_hint')"
            type="button"
            :class="BUTTON"
            :aria-label="trans('common.zoom_in')"
            :disabled="scale >= max"
            @click="$emit('in')"
        >
            <Plus class="size-5" :stroke-width="2.5" />
        </button>
        <button
            v-tooltip="trans('common.zoom_out')"
            type="button"
            :class="BUTTON"
            :aria-label="trans('common.zoom_out')"
            :disabled="scale <= 1"
            @click="$emit('out')"
        >
            <Minus class="size-5" :stroke-width="2.5" />
        </button>
        <button
            v-tooltip="trans('common.zoom_reset')"
            type="button"
            :class="BUTTON"
            :aria-label="trans('common.zoom_reset')"
            :disabled="scale <= 1"
            @click="$emit('reset')"
        >
            <Maximize class="size-4" :stroke-width="2.5" />
        </button>
    </div>
</template>
