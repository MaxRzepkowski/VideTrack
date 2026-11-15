<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ArrowDownWideNarrow, ArrowUpNarrowWide } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The sort axis and its direction, as one control: the axis select and the
 * direction toggle share a single frame, so the pair reads as one thing rather
 * than as two neighbouring controls. Every view that sorts uses this.
 *
 * An option may declare itself one-way (`directional: false`) — discover sorts
 * come from a provider, and nobody browses by least popular. The toggle then
 * stays in place, disabled, rather than moving the select under the cursor.
 */
const props = defineProps<{ options: { value: string; label: string; directional?: boolean }[] }>();

const sort = defineModel<string>('sort', { required: true });
const dir = defineModel<'asc' | 'desc'>('dir', { required: true });

const directional = computed(() => props.options.find((option) => option.value === sort.value)?.directional !== false);
</script>

<template>
    <!-- `h-10` on the frame, `h-full` inside: the shared 40px toolbar height is
         the frame's own box (border included), not the sum of frame + child. -->
    <div
        data-toolbar-control
        class="border-line bg-surface hover:border-line-strong focus-within:border-brand flex h-10 items-center rounded-lg border transition-colors duration-150"
    >
        <div class="flex h-full items-center pl-3.5">
            <select
                v-model="sort"
                data-toolbar-control
                :aria-label="trans('app.sort')"
                class="bg-surface text-ink h-full cursor-pointer rounded-lg pr-2 text-base font-semibold outline-none"
            >
                <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </div>
        <button
            v-tooltip="dir === 'desc' ? trans('app.descending') : trans('app.ascending')"
            type="button"
            :disabled="!directional"
            class="border-line text-ink-secondary hover:text-ink flex h-full w-10 shrink-0 items-center justify-center border-l transition-colors duration-150 disabled:pointer-events-none disabled:opacity-40"
            @click="dir = dir === 'desc' ? 'asc' : 'desc'"
        >
            <ArrowDownWideNarrow v-if="dir === 'desc'" class="size-4" />
            <ArrowUpNarrowWide v-else class="size-4" />
        </button>
    </div>
</template>
