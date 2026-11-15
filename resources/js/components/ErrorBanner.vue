<script setup lang="ts">
// A page whose source failed in part: said once, above the results.
defineProps<{ message: string }>();
</script>

<template>
    <div class="border-danger-line bg-danger-surface text-danger mb-8 rounded-xl border p-6 text-base" role="alert">
        {{ message }}
    </div>
</template>
