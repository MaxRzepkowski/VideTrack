<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { SlidersHorizontal } from '@lucide/vue';

// The toolbar's "Filters" button with the count of filters in force, in the
// two looks a toolbar comes in: on the page, or on its full-width band.
withDefaults(
    defineProps<{
        count: number;
        /** Lit: filters in force, or the panel they live in open. */
        active: boolean;
        expanded: boolean;
        variant?: 'default' | 'band';
    }>(),
    { variant: 'default' },
);

defineEmits<{ click: [] }>();
</script>

<template>
    <button
        type="button"
        class="flex h-10 items-center gap-2 rounded-lg border px-3.5 text-base font-semibold transition-colors duration-150"
        :class="
            variant === 'band'
                ? active
                    ? 'border-brand bg-brand hover:bg-brand-strong text-on-accent'
                    : 'border-line bg-surface-control text-ink-secondary hover:border-line-strong'
                : active
                  ? 'border-brand bg-brand/15 text-on-accent'
                  : 'border-line bg-surface text-ink-secondary hover:border-line-strong'
        "
        :aria-expanded="expanded"
        @click="$emit('click')"
    >
        <SlidersHorizontal class="size-4" />
        {{ trans('common.filters') }}
        <span
            v-if="count > 0"
            class="text-on-photo rounded-full px-1.5 text-base font-bold"
            :class="variant === 'band' ? 'bg-on-photo/20' : 'bg-brand'"
        >
            {{ count }}
        </span>
    </button>
</template>
