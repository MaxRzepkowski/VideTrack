<script setup lang="ts">
import { formatNumber } from '@/lib/format';
import { computed } from 'vue';

/**
 * Horizontal bars, one row per thing: its name, the bar, the number. The
 * largest row is lit. Read row by row, so the list is its own text.
 */
const props = withDefaults(
    defineProps<{
        rows: { key: string; label: string; value: number }[];
        color?: string;
        highlightColor?: string;
    }>(),
    { color: 'var(--color-brand)', highlightColor: 'var(--color-brand-soft)' },
);

const max = computed(() => Math.max(1, ...props.rows.map((row) => row.value)));
const peak = computed(() => (max.value > 0 ? props.rows.findIndex((row) => row.value === max.value) : -1));
</script>

<template>
    <ul class="m-0 grid list-none grid-cols-[auto_minmax(0,1fr)] items-center gap-x-6 gap-y-4 p-0">
        <li v-for="(row, index) in rows" :key="row.key" class="contents">
            <span class="text-ink-secondary text-sm">{{ row.label }}</span>
            <span class="flex min-w-0 items-center gap-3">
                <span
                    class="block h-3.5 rounded-[2px]"
                    :style="{ width: `${(row.value / max) * 85}%`, backgroundColor: index === peak ? highlightColor : color }"
                    aria-hidden="true"
                />
                <span class="text-sm tabular-nums" :class="index === peak ? 'text-ink' : 'text-ink-muted'">{{ formatNumber(row.value) }}</span>
            </span>
        </li>
    </ul>
</template>
