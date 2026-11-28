<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { trans } from 'laravel-vue-i18n';
import { Check, LoaderCircle } from '@lucide/vue';

withDefaults(
    defineProps<{
        dirty: boolean;
        processing: boolean;
        /**
         * Whose form it saves. A page's floats over the bottom of the screen the whole
         * time, so saving is never something to look for. A card's (one channel, one
         * webhook, one account among others) is only its two buttons under its fields,
         * while that card has something to save: a bar in every card would crowd them.
         */
        placement?: 'page' | 'card';
    }>(),
    { placement: 'page' },
);

defineEmits<{
    save: [];
    discard: [];
}>();
</script>

<template>
    <!-- A card's status is for a screen reader alone, and stays mounted: a live region read out is one already there. -->
    <span v-if="placement === 'card'" class="sr-only" role="status" aria-live="polite">
        {{ dirty || processing ? trans('settings.you_have_unsaved_changes') : '' }}
    </span>
    <div v-if="placement === 'page' || dirty || processing" :class="{ 'sticky bottom-4 z-10': placement === 'page' }">
        <div
            :class="
                placement === 'page'
                    ? [
                          'bg-surface-raised flex flex-wrap items-center justify-between gap-3 rounded-xl border py-2.5 pr-2.5 pl-5 shadow-lg transition-colors duration-150',
                          dirty || processing ? 'border-brand' : 'border-line',
                      ]
                    : 'flex items-center justify-end gap-2'
            "
        >
            <p v-if="placement === 'page'" class="m-0 flex items-center gap-2 text-sm" role="status" aria-live="polite">
                <template v-if="dirty || processing">
                    <span class="text-ink font-medium">{{ trans('settings.you_have_unsaved_changes') }}</span>
                </template>
                <template v-else>
                    <Check class="text-ink-muted size-4" aria-hidden="true" />
                    <span class="text-ink-muted">{{ trans('settings.all_changes_saved') }}</span>
                </template>
            </p>
            <div :class="placement === 'page' ? 'ml-auto flex items-center gap-2' : 'contents'">
                <Button v-if="dirty" type="button" variant="ghost" size="sm" :disabled="processing" @click="$emit('discard')">
                    {{ trans('settings.discard') }}
                </Button>
                <Button type="button" size="sm" :disabled="!dirty || processing" @click="$emit('save')">
                    <LoaderCircle v-if="processing" class="animate-spin" aria-hidden="true" />
                    {{ processing ? trans('common.saving') : trans('common.save') }}
                </Button>
            </div>
        </div>
    </div>
</template>
