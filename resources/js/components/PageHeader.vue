<script setup lang="ts">
/**
 * The header every "owned page" wears: a strip of meta pills, then the page's
 * own title and actions, then an optional nav strip (tabs, filters — whatever
 * the page switches with).
 *
 * One shape, everywhere. The block folds down so a page's own content starts
 * near the top of the viewport — identity is context here, and a band of it
 * would be the tallest thing on the page. A profile is no exception: it wears
 * the same header its own panels do, so walking between them moves nothing.
 *
 * An image a page owns is the page's business, not the header's: it goes in
 * the block below, beside whatever that page opens with.
 */
withDefaults(
    defineProps<{
        /** Optional: a panel whose nav names it needs no heading. */
        title?: string | null;
        /**
         * How far the nav sits below the pills. `tight` is for a header the
         * pills only sign — one chip and the tabs read as a pair, and the
         * normal gap sets them apart as two separate statements.
         */
        navGap?: 'normal' | 'tight';
    }>(),
    { title: null, navGap: 'normal' },
);
</script>

<template>
    <div class="max-w-content mx-auto w-full px-4 md:px-10">
        <!-- The meta pills open the block, like a list. -->
        <div class="relative z-10 flex flex-wrap items-center gap-2 pt-7">
            <slot name="meta" />
        </div>

        <div v-if="title || $slots.actions" class="mt-5 flex flex-col items-start gap-3 sm:flex-row sm:justify-between sm:gap-6">
            <h1 v-if="title" class="text-title m-0 flex min-w-0 items-center gap-3 font-semibold">
                {{ title }}
                <slot name="title-badge" />
            </h1>
            <div class="flex shrink-0 flex-wrap items-center gap-3 sm:pt-1"><slot name="actions" /></div>
        </div>

        <div :class="navGap === 'tight' ? 'mt-3' : 'mt-5'"><slot name="nav" /></div>
    </div>
</template>
