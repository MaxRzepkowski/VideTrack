<script setup lang="ts">
// The one section-heading scale used across the app: every in-page section
// gets a 24px semibold title with an optional muted subtitle; page-level
// titles are 28px (`text-title`) in the views. `compactOnMobile` steps the
// title down to 20px below `xs`, where a long one would wrap.
withDefaults(
    defineProps<{
        title: string;
        subtitle?: string | null;
        compactOnMobile?: boolean;
    }>(),
    { subtitle: null, compactOnMobile: false },
);
</script>

<template>
    <div class="mb-5">
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
            <div class="flex min-w-0 items-start gap-3">
                <span v-if="$slots.icon" class="text-brand-soft mt-1 flex size-6 shrink-0 items-center justify-center" aria-hidden="true">
                    <slot name="icon" />
                </span>
                <!-- `badge` rides beside the title (a source credit, a counter);
                     the default slot is the section's action, at the far end. -->
                <div class="flex min-w-0 flex-wrap items-baseline gap-2">
                    <h2 class="m-0 font-semibold" :class="compactOnMobile ? 'xs:text-2xl text-xl' : 'text-2xl'">{{ title }}</h2>
                    <slot name="badge" />
                </div>
            </div>
            <slot />
        </div>
        <p v-if="subtitle" class="text-ink-muted m-0 mt-1 text-base">{{ subtitle }}</p>
    </div>
</template>
