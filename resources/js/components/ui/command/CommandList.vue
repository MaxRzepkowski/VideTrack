<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ComboboxContent } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <!-- `dismissable` off: the dialog around the palette already owns Esc and
         outside clicks, and a second dismissable layer over the same overlay
         would race it for the key. -->
    <ComboboxContent
        position="inline"
        :dismissable="false"
        :class="cn('max-h-[60dvh] overflow-x-hidden overflow-y-auto overscroll-contain p-2', props.class)"
    >
        <slot />
    </ComboboxContent>
</template>
