<script setup lang="ts">
import { useClampOverflow } from '@/composables/useClampOverflow';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

// A biography, a description or a storyline, clamped to a few lines (six
// unless told). The toggle appears only when the text actually overflows the
// clamp. Line breaks the provider kept (a book's paragraphs) stay.
const props = withDefaults(defineProps<{ text: string; lines?: 4 | 6 }>(), { lines: 6 });

// Whole class names, so Tailwind sees them.
const CLAMP: Record<4 | 6, string> = { 4: 'line-clamp-4', 6: 'line-clamp-6' };

const expanded = ref(false);
const paragraph = ref<HTMLElement | null>(null);
const overflows = useClampOverflow(paragraph, () => !expanded.value);
const clamp = computed(() => (expanded.value ? '' : CLAMP[props.lines]));
</script>

<template>
    <p ref="paragraph" class="text-ink-secondary mb-2 text-base leading-relaxed whitespace-pre-line" :class="clamp">
        {{ text }}
    </p>
    <button v-if="overflows" type="button" class="text-brand-soft p-0 text-base font-bold" @click="expanded = !expanded">
        {{ expanded ? trans('media.show_less') : trans('common.show_more') }}
    </button>
</template>
