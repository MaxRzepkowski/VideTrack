<script setup lang="ts">
// The settings toggle: 44×24 track, 18px thumb, brand colour
// when checked. The checkbox itself covers the track, so the root is a plain
// span and the switch can sit inside a caller's <label> (which then names it);
// without one, `label` gives it a name for screen readers.
defineProps<{ label?: string }>();

const model = defineModel<boolean>({ required: true });
</script>

<template>
    <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
        <input v-model="model" type="checkbox" role="switch" :aria-label="label" class="peer absolute inset-0 z-10 m-0 cursor-pointer opacity-0" />
        <span
            class="absolute inset-0 rounded-full bg-line transition-colors duration-150 peer-checked:bg-brand peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-soft"
        ></span>
        <span
            class="absolute left-0.75 top-0.75 size-4.5 rounded-full bg-ink transition-transform duration-150 peer-checked:translate-x-5"
        ></span>
    </span>
</template>
