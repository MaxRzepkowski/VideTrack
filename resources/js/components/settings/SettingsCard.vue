<script setup lang="ts">
import { SETTINGS_CARD_CLASS } from '@/lib/ui';

/** One card of settings that belong together, each a {@see SettingsRow.vue}, set apart by a hairline. */
defineProps<{
    /** The name of the group, when a page holds more than one. */
    title?: string;
    description?: string;
}>();
</script>

<template>
    <section class="space-y-3">
        <!-- A title under the page's own heading; the actions slot holds what acts on the whole card (clearing it). -->
        <header v-if="title || description || $slots.actions" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <h4 v-if="title" class="text-ink-secondary text-base font-semibold">{{ title }}</h4>
                <p v-if="description" class="text-ink-muted mt-0.5 text-sm">{{ description }}</p>
            </div>
            <slot name="actions" />
        </header>
        <div :class="[SETTINGS_CARD_CLASS, 'divide-line-subtle divide-y']">
            <slot />
        </div>
    </section>
</template>
