<script setup lang="ts">
// The kind of a release that is not a plain one (Early Access, a next-gen
// patch), as the calendar and the countdowns both chip it: one look, in a
// colour of its own rather than a media type's.
defineProps<{ status: string }>();
</script>

<template>
    <span class="bg-release-status text-on-accent inline-block rounded-md px-2 py-0.5 text-sm font-extrabold">{{ status }}</span>
</template>
