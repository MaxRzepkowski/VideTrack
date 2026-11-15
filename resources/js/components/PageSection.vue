<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, useAttrs } from 'vue';

/**
 * One band of a page. The band itself spans the full viewport width, so its
 * tone reaches both screen edges; only the content inside is held to the
 * shared container width.
 *
 * A page is a *list* of these — never one container with sections inside it,
 * which would box a section's background into the content column.
 */
const props = withDefaults(
    defineProps<{
        /** Band background. `plain` inherits the page background. */
        tone?: 'plain' | 'subtle' | 'raised';
        /** Vertical rhythm inside the band. */
        spacing?: 'none' | 'tight' | 'normal' | 'loose';
        /** Container width: the 1400px content column, or the 1530px hero one. */
        width?: 'content' | 'wide';
        /** Rendered element — `div` for bands that are not a section. */
        as?: string;
    }>(),
    { tone: 'plain', spacing: 'normal', width: 'content', as: 'section' },
);

// `class` is merged by hand (twMerge) so a caller's `pt-*` / `pb-*` beats the
// spacing preset instead of stacking with it.
defineOptions({ inheritAttrs: false });
const attrs = useAttrs();

// A hairline top and bottom, like every other surface in the app — the step in
// background alone would leave the band's edge undefined. Two bands of the same tone
// in a row would meet bottom-to-top and draw it twice, a 2px stripe among 1px
// seams; the second one drops its top edge and they share the first one's.
const TONES = {
    plain: '',
    subtle: 'bg-surface-band border-line-faint border-y [[data-tone=subtle]+&]:border-t-0',
    raised: 'bg-surface border-line-faint border-y [[data-tone=raised]+&]:border-t-0',
} as const;

const SPACINGS = { none: '', tight: 'py-6', normal: 'py-8', loose: 'py-14' } as const;

const bandClass = computed(() => cn('w-full', TONES[props.tone], SPACINGS[props.spacing], attrs.class));
const innerClass = computed(() => cn('mx-auto w-full px-4 md:px-10', props.width === 'wide' ? 'max-w-wide' : 'max-w-content'));
</script>

<template>
    <!-- `data-tone` is what lets a band see the tone of the one above it. -->
    <component :is="as" v-bind="{ ...$attrs, class: undefined }" :data-tone="tone" :class="bandClass">
        <div :class="innerClass">
            <slot />
        </div>
    </component>
</template>
