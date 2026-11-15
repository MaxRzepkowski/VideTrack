<script setup lang="ts">
import { transChoice } from 'laravel-vue-i18n';
import { Globe, Lock } from '@lucide/vue';

// Under a list's cover: its name, how many positions it holds, and whether
// others can see it. `linked` lights the name when the card is a link.
defineProps<{ title: string; itemCount: number; isPublic: boolean; linked?: boolean }>();
</script>

<template>
    <div class="flex items-center justify-between gap-3 p-4">
        <div class="min-w-0">
            <div class="text-ink truncate text-base font-bold" :class="linked ? 'group-hover:text-brand-soft transition-colors duration-150' : ''">
                {{ title }}
            </div>
            <div class="text-ink-muted mt-0.5 text-sm font-semibold">
                {{ transChoice('common.count_item', itemCount) }}
            </div>
        </div>
        <Globe v-if="isPublic" class="text-ink-muted size-4 shrink-0" />
        <Lock v-else class="text-ink-muted size-4 shrink-0" />
    </div>
</template>
