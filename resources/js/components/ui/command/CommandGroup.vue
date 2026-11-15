<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ComboboxGroup, ComboboxLabel } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{ heading: string; class?: HTMLAttributes['class'] }>();
</script>

<template>
    <ComboboxGroup :class="cn('pb-2 last:pb-0', props.class)">
        <ComboboxLabel class="text-ink-muted px-2 py-1.5 text-sm font-semibold">{{ props.heading }}</ComboboxLabel>
        <slot />
    </ComboboxGroup>
</template>
