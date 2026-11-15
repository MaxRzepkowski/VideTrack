<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ComboboxInput } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{ class?: HTMLAttributes['class']; placeholder?: string }>();
</script>

<template>
    <div class="border-line-subtle flex items-center gap-3 border-b px-4">
        <slot name="icon" />

        <!-- The combobox is rendered already open, so nothing focuses the input
             for us the way opening a closed one would. -->
        <ComboboxInput
            auto-focus
            :placeholder="props.placeholder"
            :class="
                cn(
                    'text-ink placeholder:text-ink-faint h-14 w-full bg-transparent text-base outline-none disabled:cursor-not-allowed disabled:opacity-50',
                    props.class,
                )
            "
        />

        <slot name="trailing" />
    </div>
</template>
