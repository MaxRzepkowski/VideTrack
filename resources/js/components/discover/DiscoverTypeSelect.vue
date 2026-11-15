<script setup lang="ts">
import { discoverTypeLabel } from '@/lib/discover';
import { SELECT_CLASS } from '@/lib/ui';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    type: string;
    /** In the backend's order (registry order, people last); there is no "all" entry. */
    availableTypes: string[];
    /** Label above the select, as the other filter fields of a panel have it. */
    stacked?: boolean;
}>();

const options = computed(() => props.availableTypes.map((type) => ({ value: type, label: discoverTypeLabel(type) })));

function onChange(event: Event): void {
    // Switching type resets the filters — they belong to the provider.
    router.get(route('discover', { type: (event.target as HTMLSelectElement).value }), {}, { preserveScroll: false });
}
</script>

<template>
    <!-- Inline, it hides below `md`: the type is reachable from the nav
         drawer, which lists every discoverable type on its own. -->
    <label :class="stacked ? 'flex flex-col gap-1.5' : 'hidden items-center gap-2 md:flex'">
        <span class="text-ink-muted text-base font-bold">{{ trans('discover.media_type') }}</span>
        <select data-toolbar-control :value="type" :class="SELECT_CLASS" @change="onChange">
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
    </label>
</template>
