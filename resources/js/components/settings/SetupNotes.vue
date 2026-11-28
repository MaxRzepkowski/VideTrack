<script setup lang="ts">
import { displayUrl } from '@/lib/urls';
import type { SetupNote } from '@/types/generated';

/** What a source needs before it can be used: where to get the file, what to type, what the service must allow. */
defineProps<{ notes: SetupNote[] }>();
</script>

<template>
    <div v-if="notes.length" class="text-ink-muted space-y-1 text-sm">
        <p v-for="(note, index) in notes" :key="index">
            {{ note.text }}
            <a v-if="note.url" :href="note.url" target="_blank" rel="noopener noreferrer" class="text-brand-soft font-medium hover:underline">{{
                note.linkText ?? displayUrl(note.url)
            }}</a>
        </p>
    </div>
</template>
