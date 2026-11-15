<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Search } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * The toolbar's search box. Debounced, so typing does not fire a request per
 * keystroke, and the model only updates once the user pauses — callers can
 * bind it straight to a visit.
 */
const props = withDefaults(defineProps<{ placeholder?: string | null; delay?: number }>(), { placeholder: null, delay: 350 });

const model = defineModel<string | null>({ required: true });

const term = ref(model.value ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;

// The model is the query string, which a visit can change under us (paging,
// clearing filters) — follow it, but never while the user is mid-word.
watch(model, (value) => {
    if (timer === undefined) {
        term.value = value ?? '';
    }
});

function onInput(): void {
    clearTimeout(timer);
    timer = setTimeout(() => {
        timer = undefined;
        model.value = term.value.trim() || null;
    }, props.delay);
}

// Nothing may commit on behalf of a box that is gone — a visit fired from a
// dead instance reads as the page navigating on its own.
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="relative">
        <Search class="text-ink-faint pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
        <input
            v-model="term"
            data-toolbar-control
            type="search"
            :placeholder="placeholder ?? trans('app.search_by_title')"
            :aria-label="placeholder ?? trans('app.search_by_title')"
            class="border-line bg-surface text-ink focus:border-brand h-10 w-full rounded-lg border pr-3 pl-9 text-base font-semibold transition-colors duration-150 outline-none sm:w-56"
            @input="onInput"
        />
    </div>
</template>
