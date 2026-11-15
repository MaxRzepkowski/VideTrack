<script setup lang="ts">
import { cn } from '@/lib/utils';
import { trans } from 'laravel-vue-i18n';
import { ChevronDown, ChevronUp, LoaderCircle } from 'lucide-vue-next';
import { computed, useAttrs } from 'vue';

/**
 * The "Show :count more / Show fewer" pill under a capped grid. Every section
 * that caps its list uses it, so the control reads the same everywhere.
 *
 * On a shelf whose provider hands out its catalogue in chunks (a company's
 * filmography, a network's showography) the same pill also pulls the next
 * chunk: one control per shelf, reading in turn "reveal what is here", "fetch
 * what is behind it", and finally "fold it back up". Two pills stacked under
 * one grid would say the same word twice and mean different things.
 */
defineProps<{
    hiddenCount: number;
    /** The provider still holds titles past the ones the page has. */
    canLoadMore?: boolean;
    /** A chunk is on its way. */
    loading?: boolean;
}>();

const expanded = defineModel<boolean>({ required: true });

const emit = defineEmits<{ more: [] }>();

/** Whatever the pill currently offers: reveal, then fetch, then fold back. */
function press(hiddenCount: number, canLoadMore: boolean): void {
    if (hiddenCount > 0 && !expanded.value) {
        expanded.value = true;
    } else if (canLoadMore) {
        emit('more');
    } else {
        expanded.value = false;
    }
}

// `class` is merged by hand (twMerge) so a caller placing the pill differently
// — sticky inside a scrolling grid, say — overrides the default box.
defineOptions({ inheritAttrs: false });
const attrs = useAttrs();

const wrapperClass = computed(() => cn('mt-6 flex justify-center', attrs.class));
</script>

<template>
    <div v-bind="{ ...$attrs, class: undefined }" :class="wrapperClass">
        <button
            type="button"
            :disabled="loading"
            :aria-expanded="expanded"
            class="border-line bg-surface text-ink-secondary hover:border-line-strong hover:text-ink flex items-center gap-2 rounded-full border px-6 py-2.5 text-sm font-semibold transition-colors duration-150 disabled:opacity-60"
            @click="press(hiddenCount, canLoadMore ?? false)"
        >
            <!-- Cards still hidden under the fold: reveal them first. -->
            <template v-if="hiddenCount > 0 && !expanded">
                <ChevronDown class="size-4" />
                {{ trans('app.show_count_more', { count: String(hiddenCount) }) }}
            </template>
            <!-- Everything the page holds is on screen; the rest is a request away. -->
            <template v-else-if="canLoadMore">
                <LoaderCircle v-if="loading" class="size-4 animate-spin" />
                <ChevronDown v-else class="size-4" />
                {{ loading ? trans('common.loading') : trans('common.show_more') }}
            </template>
            <template v-else>
                <ChevronUp class="size-4" />
                {{ trans('app.show_fewer') }}
            </template>
        </button>
    </div>
</template>
