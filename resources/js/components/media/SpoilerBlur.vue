<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Eye } from '@lucide/vue';

defineProps<{
    /** Whether the content is currently hidden (preference on + unwatched). */
    active: boolean;
}>();

const emit = defineEmits<{ reveal: [] }>();
</script>

<template>
    <div class="relative">
        <div class="h-full w-full" :class="active ? 'pointer-events-none blur-xl select-none' : ''">
            <slot />
        </div>
        <button
            v-if="active"
            type="button"
            class="text-ink-secondary hover:text-ink absolute inset-0 z-10 flex items-center justify-center gap-2 transition-colors duration-150"
            @click.prevent.stop="emit('reveal')"
        >
            <Eye class="size-5" :stroke-width="2" />
            <span class="text-sm font-semibold">{{ trans('media.click_to_reveal') }}</span>
        </button>
    </div>
</template>
