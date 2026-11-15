<script setup lang="ts">
import { withCount } from '@/lib/format';
import { MEDIA_TYPE_META } from '@/lib/mediaTypes';
import { SELECT_CLASS } from '@/lib/ui';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * Filter by media type. Always a `<select>`, never chips, and always the shared
 * type vocabulary — a new media type is one entry in `mediaTypes.ts`, not a
 * label switch copied into another view. `null` is "All".
 */
const props = withDefaults(
    defineProps<{
        /**
         * Types present in the data, each optionally with how many. `label` is
         * only for entries that are not media types at all (the followed list
         * mixes in people, companies and collections).
         */
        options: { type: string; count?: number; label?: string }[];
        /**
         * Total behind "All", for options without counts. Options that carry
         * counts add up to it themselves — counted before the type filter, so
         * "All" keeps naming the whole set whichever type is picked.
         */
        total?: number | null;
        /** Stacked caption above the control, as the filter panel's fields wear. */
        labelled?: boolean;
    }>(),
    { total: null, labelled: false },
);

const allTotal = computed<number | null>(() => {
    if (props.total !== null) {
        return props.total;
    }

    const counts = props.options.map((option) => option.count);

    return counts.length > 0 && counts.every((count) => count !== undefined) ? counts.reduce((sum, count) => sum + count, 0) : null;
});

const model = defineModel<string | null>({ required: true });

const label = (option: { type: string; count?: number; label?: string }): string => {
    const name = option.label ?? MEDIA_TYPE_META[option.type]?.label() ?? option.type;

    return option.count === undefined ? name : withCount(name, option.count);
};
</script>

<template>
    <component :is="labelled ? 'label' : 'div'" :class="labelled ? 'flex flex-col gap-1.5' : 'contents'">
        <span v-if="labelled" class="text-ink-muted text-base font-bold">{{ trans('app.type') }}</span>
        <select v-model="model" data-toolbar-control :class="SELECT_CLASS" :aria-label="labelled ? undefined : trans('app.filter_by_type')">
            <option :value="null">{{ allTotal === null ? trans('common.all') : withCount(trans('common.all'), allTotal) }}</option>
            <option v-for="option in options" :key="option.type" :value="option.type">{{ label(option) }}</option>
        </select>
    </component>
</template>
