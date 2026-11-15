<script setup lang="ts">
import SpoilerBlur from '@/components/media/SpoilerBlur.vue';
import { useClampOverflow } from '@/composables/useClampOverflow';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

// An episode's description on its season's page: clamped to three lines, with
// a "Show more" only when it runs past them, and blurred while it would spoil.
defineProps<{ text: string; blurred: boolean }>();

const emit = defineEmits<{ reveal: [] }>();

const expanded = ref(false);
const paragraph = ref<HTMLElement | null>(null);
const overflows = useClampOverflow(paragraph, () => !expanded.value);
</script>

<template>
    <SpoilerBlur :active="blurred" @reveal="emit('reveal')">
        <p ref="paragraph" class="text-ink-secondary m-0 leading-relaxed" :class="{ 'line-clamp-3': !expanded }">
            {{ text }}
        </p>
    </SpoilerBlur>
    <button v-if="overflows" type="button" class="text-brand-soft mt-1.5 self-start text-sm font-semibold" @click="expanded = !expanded">
        {{ expanded ? trans('media.show_less') : trans('common.show_more') }}
    </button>
</template>
