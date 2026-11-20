<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        /** The id of the field the title labels; omitted when the control labels itself (a switch, buttons). */
        for?: string;
        /** Where the control sits: under the title (`stacked`, a field) or beside the text (`inline`, a switch, buttons). */
        layout?: 'stacked' | 'inline';
        /** The validation message for the control: under the field, or under the text beside a switch. */
        error?: string;
    }>(),
    { description: undefined, for: undefined, layout: 'stacked', error: undefined },
);
</script>

<template>
    <div :class="['py-5 first:pt-0 last:pb-0', { 'flex items-center justify-between gap-6': layout === 'inline' }]">
        <div class="min-w-0">
            <Label v-if="props.for" :for="props.for" class="text-ink text-base font-semibold">{{ title }}</Label>
            <div v-else class="text-ink text-base font-semibold">{{ title }}</div>
            <slot name="description">
                <p v-if="description" class="text-ink-muted mt-0.5 text-sm">{{ description }}</p>
            </slot>
            <InputError v-if="layout === 'inline'" class="mt-1" :message="error" />
        </div>
        <div :class="layout === 'inline' ? 'flex shrink-0 items-center gap-3' : 'mt-3 min-w-0'">
            <slot />
            <InputError v-if="layout === 'stacked'" class="mt-2" :message="error" />
        </div>
    </div>
</template>
