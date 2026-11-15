<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';

// The one sign an event on the air wears everywhere (the "live" button, the
// calendar, countdowns, its page): a red, beating dot and "Live". The dot is
// allowed despite the no-dots rule: it is the sign of a broadcast.
withDefaults(defineProps<{ label?: string | null }>(), { label: null });
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-2 font-bold">
        <span class="relative flex size-2.5 shrink-0" aria-hidden="true">
            <span class="bg-live absolute inset-0 rounded-full opacity-75 motion-safe:animate-ping"></span>
            <span class="bg-live relative size-2.5 rounded-full"></span>
        </span>
        <span class="truncate">{{ label ?? trans('calendar.live') }}</span>
    </span>
</template>
