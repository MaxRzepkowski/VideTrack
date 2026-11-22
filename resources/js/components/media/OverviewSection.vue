<script setup lang="ts">
import ClampedText from '@/components/ClampedText.vue';
import CreditHighlights from '@/components/media/CreditHighlights.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import type { CreditPerson } from '@/lib/creators';
import { trans } from 'laravel-vue-i18n';

// A title page's synopsis, with the key people behind it as its byline.
// Clamped like every long text on a page: a comic's or a book's description
// can run for screens before the facts below it.
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
        <ClampedText :text="overview ?? trans('media.no_overview_available')" />
        <CreditHighlights :people="people" class="mt-4" />
    </section>
</template>
