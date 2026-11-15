<script setup lang="ts">
import DiscoverFilterField from '@/components/discover/DiscoverFilterField.vue';
import type { DiscoverActiveFilters, DiscoverFilter, DiscoverRange } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * The whole filter set of one provider, rendered from its definitions. Sort is
 * deliberately absent: it lives in the toolbar above the results (filters
 * on the left, sorting and the count in the strip), so this component takes the definitions as given and only skips that one key.
 *
 * It owns no state. The page holds the pending selection and passes it down,
 * because the same set is drawn twice — sidebar on a wide screen, dialog on a
 * narrow one — and those two must never disagree.
 */
const props = defineProps<{
    filters: DiscoverFilter[];
    modelValue: DiscoverActiveFilters;
}>();

const emit = defineEmits<{
    update: [key: string, value: string | string[] | DiscoverRange | undefined];
    clear: [];
}>();

const fields = computed(() =>
    props.filters.filter(
        (filter) =>
            filter.key !== 'sort' &&
            (filter.kind === 'range' || filter.unavailable || filter.options.length > 0 || props.modelValue[filter.key] !== undefined),
    ),
);

const activeCount = computed(() => Object.keys(props.modelValue).length);
</script>

<template>
    <div class="space-y-5">
        <DiscoverFilterField
            v-for="filter in fields"
            :key="filter.key"
            :filter="filter"
            :value="modelValue[filter.key]"
            @update="(value) => emit('update', filter.key, value)"
        />

        <button
            v-if="activeCount > 0"
            type="button"
            class="text-brand-soft hover:text-brand text-base font-semibold transition-colors duration-150"
            @click="emit('clear')"
        >
            {{ trans('common.clear_filters') }}
        </button>
    </div>
</template>
