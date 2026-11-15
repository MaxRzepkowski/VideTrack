<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle } from '@lucide/vue';

defineProps<{
    dirty: boolean;
    processing: boolean;
}>();

defineEmits<{
    save: [];
    discard: [];
}>();
</script>

<template>
    <div
        class="border-line-subtle bg-surface/95 sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 border-t px-5 py-3 backdrop-blur"
    >
        <p class="text-ink-muted m-0 text-sm" role="status" aria-live="polite">
            <template v-if="dirty">{{ trans('settings.you_have_unsaved_changes') }}</template>
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
</template>
