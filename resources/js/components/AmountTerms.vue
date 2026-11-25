<script setup lang="ts">
import { durationTermsSpaced, type AmountTerm } from '@/lib/format';

/**
 * An amount set out term by term ("3 days 7 hours", "320 pages") with the
 * number in its own element, so a page can make it bolder or larger. The
 * words, their order and their spacing are the language's own: a space it
 * writes becomes the page's gap, and a language that writes none ("3日7時間")
 * gets none.
 */
withDefaults(
    defineProps<{
        terms: AmountTerm[];
        valueClass?: string;
        wordClass?: string;
        /** The space after words that come before the number, where the language writes one. */
        spaceAfterWords?: string;
        /** The space before words that follow the number, where the language writes one. */
        spaceBeforeWords?: string;
        /** The gap between terms, where the language spaces them. */
        termGap?: string;
    }>(),
    { valueClass: '', wordClass: '', spaceAfterWords: 'mr-1', spaceBeforeWords: 'ml-1', termGap: 'gap-x-2' },
);

const spacedTerms = durationTermsSpaced();
</script>

<template>
    <span class="inline-flex flex-wrap items-baseline" :class="spacedTerms ? termGap : ''">
        <span v-for="(term, index) in terms" :key="index" class="inline-flex items-baseline">
            <span v-if="term.before.trim() !== ''" :class="[wordClass, /\s$/.test(term.before) ? spaceAfterWords : '']">{{
                term.before.trim()
            }}</span>
            <span :class="valueClass">{{ term.value }}</span>
            <span v-if="term.after.trim() !== ''" :class="[wordClass, /^\s/.test(term.after) ? spaceBeforeWords : '']">{{ term.after.trim() }}</span>
        </span>
    </span>
</template>
