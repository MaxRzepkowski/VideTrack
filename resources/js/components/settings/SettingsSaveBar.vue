<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { trans } from 'laravel-vue-i18n';
import { Check, LoaderCircle } from '@lucide/vue';

withDefaults(
    defineProps<{
        dirty: boolean;
        processing: boolean;
        /**
         * Whose form it saves. A page's stays in view the whole time, so saving is never
         * something to look for; a card's (one channel, one webhook among others) shows
         * only while that card has something to save, or every card would carry a bar.
         * Both float over the bottom of the screen and look the same.
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
    <div v-if="placement === 'page' || dirty || processing" class="sticky bottom-4 z-10">
        <div
            :class="[
                'bg-surface-raised flex flex-wrap items-center justify-between gap-3 rounded-xl border py-2.5 pr-2.5 pl-5 shadow-lg transition-colors duration-150',
                dirty || processing ? 'border-brand' : 'border-line',
            ]"
        >
            <p class="m-0 flex items-center gap-2 text-sm" role="status" aria-live="polite">
                <template v-if="dirty || processing">
                    <span class="text-ink font-medium">{{ trans('settings.you_have_unsaved_changes') }}</span>
                </template>
                <template v-else>
                    <Check class="text-ink-muted size-4" aria-hidden="true" />
                    <span class="text-ink-muted">{{ trans('settings.all_changes_saved') }}</span>
                </template>
            </p>
            <div class="ml-auto flex items-center gap-2">
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
