<script setup lang="ts">
import { transChoice } from 'laravel-vue-i18n';

// The line under a progress bar, shared by every shelf that shows one (up
// next, currently playing / reading, the In Progress tab): where the title
// stands, then — behind a hairline, in quieter ink — how much is left.
// Remaining is null wherever there is no total to count down to.
withDefaults(defineProps<{ text: string; mobileText?: string | null; remaining?: number | null }>(), { mobileText: null });
</script>

<template>
    <div class="text-caption mt-2 flex items-center gap-2">
        <span class="text-ink-secondary truncate">
            <span v-if="mobileText" class="xs:hidden">{{ mobileText }}</span>
            <span :class="mobileText ? 'xs:inline hidden' : undefined">{{ text }}</span>
        </span>
        <template v-if="remaining !== null && remaining !== undefined">
            <span class="bg-line h-2 w-px shrink-0" aria-hidden="true"></span>
            <span class="text-ink-muted truncate">{{ transChoice('media.count_remaining', remaining) }}</span>
        </template>
    </div>
</template>
