<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

/**
 * The vertical rule that sets two facts apart on one line. It is the app's only
 * separator: a middle dot is never used, because it reads as generated copy.
 *
 * The colour answers to the SURFACE the rule lies on, never to the text it
 * divides. Drawn in `currentColor`, the rule would be as loud as whatever it
 * sits inside: white beside a white
 * counter, pink beside a failed task, purple over a backdrop — a separator
 * shouting louder than half the facts around it. A rule is punctuation. It
 * belongs to the paper, not to the sentence.
 *
 * Three papers exist in this app, so three tones:
 *
 * - `surface` — the flat dark stock: card, page, modal, dropdown, tooltip.
 *   Those sit within 0.25 of one another in contrast, so one token covers
 *   them all and the rule reads identically wherever it lands.
 * - `media` — over a poster, backdrop or gradient, where the stock is a
 *   photograph and no fixed colour survives both its dark and its bright
 *   patches. White at low alpha rides either.
 * - `accent` — over a filled status colour, where the stock is bright: the
 *   rule has to go darker, not lighter, to stay a rule.
 *
 * Height is set in `em`, not in a fixed step, so it follows the text it
 * divides instead of standing 12px tall next to 13px type in one place and
 * 20px type in another.
 *
 * `mx-1.5` is the breathing room it needs inside flowing text; callers that
 * already space their row (a flex line with a gap — see `MetaLine.vue`) pass
 * `class="mx-0"` and let the gap do the work.
 */
const props = withDefaults(defineProps<{ tone?: 'surface' | 'media' | 'accent'; class?: HTMLAttributes['class'] }>(), {
    tone: 'surface',
    class: undefined,
});

const TONE: Record<'surface' | 'media' | 'accent', string> = {
    surface: 'bg-line-strong',
    media: 'bg-on-photo/28',
    accent: 'bg-scrim/25',
};
</script>

<template>
    <span :class="cn('mx-1.5 inline-block h-[0.8em] w-px shrink-0 align-[-0.1em]', TONE[props.tone], props.class)" aria-hidden="true"></span>
</template>
