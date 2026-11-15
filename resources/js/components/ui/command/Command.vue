<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ComboboxRoot } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();

const searchTerm = defineModel<string>('searchTerm', { default: '' });

/**
 * The highlighted row — the one Enter commits to. Exposed because radix keeps a
 * highlight whose value is still present when the rows change underneath it,
 * and only the consumer knows when that is the wrong answer; clearing it hands
 * the choice back to radix, which highlights the first row.
 */
const selectedValue = defineModel<string | undefined>('selectedValue', { default: undefined });

/**
 * The list never closes on its own: whatever wraps the command (a dialog) is
 * what opens and closes, and a combobox that shut itself would strand the
 * overlay empty.
 *
 * Filtering belongs to the consumer, hence the identity function. Radix's
 * built-in filter matches the substring against the item's *value* — ours are
 * opaque ids, labels are translated, and media rows were already matched by
 * the server, so the default would hide rows that belong.
 */
function keepAll(options: string[]): string[] {
    return options;
}
</script>

<template>
    <ComboboxRoot
        v-model:search-term="searchTerm"
        v-model:selected-value="selectedValue"
        :open="true"
        :filter-function="keepAll"
        :class="cn('flex w-full flex-col overflow-hidden', props.class)"
    >
        <slot />
    </ComboboxRoot>
</template>
