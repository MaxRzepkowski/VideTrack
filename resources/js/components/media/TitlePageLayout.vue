<script setup lang="ts">
import LibraryFallbackNotice from '@/components/media/LibraryFallbackNotice.vue';
import AppLayout from '@/layouts/AppLayout.vue';

/*
 * The frame every title page shares: the hero, the title and its content on
 * the left, the action panels on the right. The panels are rendered once and
 * placed by the grid — between the title and the content on a phone, in a
 * sticky column beside both from `lg` — so each panel is one instance.
 */
withDefaults(
    defineProps<{
        /** The provider did not answer; the page was rebuilt from the local library. */
        fromLibrary?: boolean;
        removedAtSource?: boolean;
        /** Bottom spacing of the content block: tight when more sections follow below it. */
        contentClass?: string;
    }>(),
    { fromLibrary: false, removedAtSource: false, contentClass: 'pb-8' },
);
</script>

<template>
    <AppLayout>
        <!-- Above the hero, not under it: the poster overlaps the hero's
             lower edge and would cover a notice placed below. -->
        <div v-if="fromLibrary" class="max-w-content mx-auto w-full px-4 pt-6 md:px-10">
            <LibraryFallbackNotice show :removed="removedAtSource" />
        </div>

        <slot name="hero" />

        <div class="max-w-content mx-auto w-full flex-1 px-4 md:px-10" :class="contentClass">
            <!-- The content row takes up whatever height the panels need
                 beyond the title, so a tall panel column never opens a gap
                 between the two, and no empty row adds a gap of its own. -->
            <div class="grid grid-cols-1 gap-x-10 gap-y-8 lg:grid-cols-3 lg:grid-rows-[auto_1fr]">
                <div class="lg:col-span-2">
                    <slot name="header" />
                </div>

                <div class="lg:col-start-3 lg:row-span-2 lg:row-start-1 lg:pt-8">
                    <div class="space-y-5 lg:sticky lg:top-24">
                        <slot name="panels" />
                    </div>
                </div>

                <div class="space-y-8 lg:col-span-2">
                    <slot />
                </div>
            </div>

            <slot name="below" />
        </div>

        <slot name="after" />

        <!-- Recently viewed closes the page, at the one spacing every page gives it. -->
        <div v-if="$slots.recent" class="max-w-content mx-auto w-full px-4 pt-16 pb-20 md:px-10">
            <slot name="recent" />
        </div>
    </AppLayout>
</template>
