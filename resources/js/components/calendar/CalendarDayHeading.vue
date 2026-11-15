<script setup lang="ts">
// The heading over a calendar day's column: its name and its date, ruled off.
// Today takes the brand tint; a day of the month next door reads faint.
defineProps<{
    label: string;
    date: string;
    today?: boolean;
    filler?: boolean;
}>();
</script>

<template>
    <div class="border-b-2 pb-2" :class="filler ? 'border-line-subtle/50' : 'border-line-subtle'">
        <div class="text-base font-bold first-letter:uppercase" :class="filler ? 'text-ink-faint' : today ? 'text-brand-soft' : 'text-ink'">
            {{ label }}
        </div>
        <div class="text-sm" :class="filler ? 'text-ink-faint' : 'text-ink-muted'">{{ date }}</div>
    </div>
</template>
