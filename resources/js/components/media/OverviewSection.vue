<script setup lang="ts">
import CreditHighlights from '@/components/media/CreditHighlights.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import type { CreditPerson } from '@/lib/creators';
import { trans } from 'laravel-vue-i18n';

// A title page's synopsis, with the key people behind it as its byline.
// Line breaks the provider kept (book descriptions have paragraphs) stay.
withDefaults(
    defineProps<{
        overview?: string | null;
        people?: CreditPerson[];
    }>(),
    { overview: null, people: () => [] },
);
</script>

<template>
    <section>
        <SectionHeading :title="trans('media.overview')" />
        <p class="text-ink-secondary text-base leading-relaxed whitespace-pre-line">{{ overview ?? trans('media.no_overview_available') }}</p>
        <CreditHighlights :people="people" class="mt-4" />
    </section>
</template>
