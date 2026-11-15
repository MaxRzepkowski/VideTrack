<script setup lang="ts">
import { posterGridClass } from '@/lib/ui';

/**
 * The shared card grid. `columns` is the widest it goes: five across the full
 * page, four where a sidebar has taken a column off (discover's filters).
 */
withDefaults(defineProps<{ columns?: 4 | 5 }>(), { columns: 5 });
</script>

<template>
    <div :class="posterGridClass(columns)">
        <slot />
    </div>
</template>
