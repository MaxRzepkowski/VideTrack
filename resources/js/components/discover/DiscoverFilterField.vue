<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { SELECT_CLASS } from '@/lib/ui';
import type { DiscoverFilter, DiscoverRange } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * One filter, drawn from its server-side definition. The switch on `kind` is
 * the whole point: a provider adding a filter changes nothing here, because
 * every filter is one of three shapes.
 *
 * `undefined` as a value means "not set" and is what the field emits when the
 * user empties it — the page keeps its state as a sparse map, so a filter that
 * is off simply has no entry.
 */
const props = defineProps<{
    filter: DiscoverFilter;
    value: string | string[] | DiscoverRange | undefined;
}>();

const emit = defineEmits<{ update: [value: string | string[] | DiscoverRange | undefined] }>();

const selected = computed(() => (typeof props.value === 'string' ? props.value : ''));

const checked = computed(() => (Array.isArray(props.value) ? props.value : []));

const range = computed<DiscoverRange>(() =>
    props.value !== undefined && !Array.isArray(props.value) && typeof props.value === 'object' ? props.value : { from: null, to: null },
);

/** Compact ranges use selects; broad numeric ranges use number inputs. */
const rangeOptions = computed(() => {
    const { rangeMin, rangeMax } = props.filter;

    if (rangeMin === null || rangeMax === null || rangeMax - rangeMin > 200) {
        return [];
    }

    return Array.from({ length: rangeMax - rangeMin + 1 }, (_, index) => rangeMax - index);
});

function onSelect(event: Event): void {
    const next = (event.target as HTMLSelectElement).value;

    emit('update', next === '' ? undefined : next);
}

function onToggle(optionValue: string, on: boolean): void {
    const next = on ? [...checked.value, optionValue] : checked.value.filter((value) => value !== optionValue);

    emit('update', next.length > 0 ? next : undefined);
}

function onRange(end: 'from' | 'to', event: Event): void {
    const raw = (event.target as HTMLInputElement | HTMLSelectElement).value;
    const next: DiscoverRange = { ...range.value, [end]: raw === '' ? null : Number(raw) };

    emit('update', next.from === null && next.to === null ? undefined : next);
}
</script>

<template>
    <fieldset class="border-line-faint border-b pb-5 last:border-b-0 last:pb-0">
        <legend class="text-ink mb-3 text-base font-bold">{{ filter.label }}</legend>

        <!-- A dictionary that did not load: say so, rather than offer an empty
             control the user would read as "this region has nothing". -->
        <p v-if="filter.unavailable" class="text-ink-faint m-0 text-base">{{ trans('discover.temporarily_unavailable') }}</p>

        <select
            v-else-if="filter.kind === 'select'"
            :value="selected"
            :class="[SELECT_CLASS, 'w-full']"
            :aria-label="filter.label"
            @change="onSelect"
        >
            <option value="">{{ trans('discover.any') }}</option>
            <option v-for="option in filter.options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>

        <div v-else-if="filter.kind === 'multi_select'" class="-mr-2 max-h-64 space-y-2.5 overflow-y-auto pr-2">
            <label v-for="option in filter.options" :key="option.value" class="flex cursor-pointer items-center gap-2.5">
                <Checkbox :checked="checked.includes(option.value)" @update:checked="(on: boolean) => onToggle(option.value, on)" />
                <span class="text-ink-secondary text-base">{{ option.label }}</span>
            </label>
        </div>

        <div v-else class="flex items-center gap-2">
            <label class="flex-1">
                <span class="sr-only">{{ trans('discover.range_from') }}</span>
                <select
                    v-if="rangeOptions.length"
                    :value="range.from ?? ''"
                    :class="[SELECT_CLASS, 'w-full']"
                    @change="(event) => onRange('from', event)"
                >
                    <option value="">{{ trans('discover.range_from') }}</option>
                    <option v-for="option in rangeOptions" :key="`from-${option}`" :value="option">{{ option }}</option>
                </select>
                <input
                    v-else
                    type="number"
                    inputmode="numeric"
                    :value="range.from ?? ''"
                    :min="filter.rangeMin ?? undefined"
                    :max="filter.rangeMax ?? undefined"
                    :placeholder="trans('discover.range_from')"
                    :class="[SELECT_CLASS, 'w-full']"
                    @change="(event) => onRange('from', event)"
                />
            </label>

            <span class="bg-line h-px w-2 shrink-0" aria-hidden="true" />

            <label class="flex-1">
                <span class="sr-only">{{ trans('discover.range_to') }}</span>
                <select
                    v-if="rangeOptions.length"
                    :value="range.to ?? ''"
                    :class="[SELECT_CLASS, 'w-full']"
                    @change="(event) => onRange('to', event)"
                >
                    <option value="">{{ trans('discover.range_to') }}</option>
                    <option v-for="option in rangeOptions" :key="`to-${option}`" :value="option">{{ option }}</option>
                </select>
                <input
                    v-else
                    type="number"
                    inputmode="numeric"
                    :value="range.to ?? ''"
                    :min="filter.rangeMin ?? undefined"
                    :max="filter.rangeMax ?? undefined"
                    :placeholder="trans('discover.range_to')"
                    :class="[SELECT_CLASS, 'w-full']"
                    @change="(event) => onRange('to', event)"
                />
            </label>
        </div>
    </fieldset>
</template>
