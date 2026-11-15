<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ComboboxItem } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{ value: string; class?: HTMLAttributes['class'] }>();

const emit = defineEmits<{ select: [] }>();

/**
 * Every row is a jump, not a value: preventing the default keeps radix from
 * recording a "selected option" and rewriting the input to match it.
 */
function onSelect(event: Event): void {
    event.preventDefault();
    emit('select');
}
</script>

<template>
    <ComboboxItem
        :value="props.value"
        :class="
            cn(
                'text-ink data-[highlighted]:bg-surface-raised flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-left text-sm outline-none select-none',
                props.class,
            )
        "
        @select="onSelect"
    >
        <slot />
    </ComboboxItem>
</template>
