<script setup lang="ts">
// The title block used by listing pages inside PageToolbar. Actions remain the
// toolbar's responsibility, so they can sit opposite this block.
withDefaults(
    defineProps<{
        title: string;
        subtitle?: string | null;
    }>(),
    { subtitle: null },
);
</script>

<template>
    <div class="min-w-0">
        <div class="flex min-w-0 items-center gap-3">
            <span v-if="$slots.icon" class="text-brand-soft flex size-6 shrink-0 items-center justify-center" aria-hidden="true">
                <slot name="icon" />
            </span>
            <h1 class="text-title m-0 min-w-0 font-semibold">{{ title }}</h1>
        </div>
        <p v-if="subtitle" class="text-ink-muted m-0 mt-1 text-base">{{ subtitle }}</p>
    </div>
</template>
