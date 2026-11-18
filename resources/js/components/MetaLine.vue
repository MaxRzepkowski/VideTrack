<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import { splitLastWord } from '@/lib/format';
import { computed } from 'vue';

/**
 * Several facts on one line, set apart by the shared vertical rule
 * ({@see Hairline.vue}) — never a middle dot.
 *
 * Empty parts drop out, so callers can pass an optional fact straight in
 * without composing the string themselves.
 */
// `wrap` trades the one line for the whole text: nothing is cut, the facts run
// onto as many lines as they need. The calendar asks for it — a day column is
// narrow and its cards exist to be read, not scanned past.
// `tone` is the rule's surface, forwarded untouched ({@see Hairline.vue}):
// a line of facts over a backdrop or on a filled colour says so here, because
// the rule cannot see what it lies on.
// `lead` is how many of the shown parts open the line in a brighter weight:
// a title's own totals ("8 books | 1,355 pages") before the notes about them.
const props = withDefaults(
    defineProps<{ parts: (string | null | undefined)[]; wrap?: boolean; tone?: 'surface' | 'media' | 'accent'; lead?: number }>(),
    { wrap: false, tone: undefined, lead: 0 },
);

const LEAD = 'text-ink-secondary font-semibold';

const shown = computed<string[]>(() => props.parts.filter((part): part is string => typeof part === 'string' && part !== ''));

/**
 * The one part that gives way when the line runs out of room: the longest.
 * Left to itself flex shrinks every part in proportion to its width, so a
 * narrow calendar cell would come out as "S0… | A long titl…" — the code, which
 * is the fact you scan for, mangled to save a title that still had room to
 * give. Everything but the longest part holds its full width, and the
 * title alone truncates. Which one that is has to be measured, not assumed:
 * the calendar's chips put the title first and the code second, the cards the
 * other way round.
 */
const flexible = computed<number>(() =>
    shown.value.reduce((longest, part, index, all) => (part.length > (all[longest]?.length ?? 0) ? index : longest), 0),
);

/** Zero-width space: a break opportunity that takes no room. */
const BREAK = '\u200B';
</script>

<template>
    <!-- One root either way: the caller's classes ride in on it. -->
    <span class="min-w-0" :class="wrap ? 'block wrap-break-word' : 'flex items-center gap-1.5'">
        <!-- Wrapping mode is running text, not a row of boxes: the facts flow
             into one another and a line breaks mid-fact, so what follows the
             rule keeps the rule's own line and only the overflow drops down.
             The rule is glued to the last word before it — both held on one
             line, since a browser may break before any inline box — and a
             zero-width space follows it, so the only break the browser can
             take near the rule is the one AFTER it, and no line ever opens
             with a dangling hairline. -->
        <template v-if="wrap">
            <template v-for="(part, index) in shown" :key="index"
                ><span :class="index < lead ? LEAD : undefined"
                    ><template v-if="index < shown.length - 1"
                        >{{ splitLastWord(part).head
                        }}<span class="whitespace-nowrap">{{ splitLastWord(part).last }}<Hairline :tone="tone" /></span></template
                    ><template v-else>{{ part }}</template></span
                ><template v-if="index < shown.length - 1">{{ BREAK }}</template></template
            >
        </template>
        <template v-else>
            <template v-for="(part, index) in shown" :key="index">
                <Hairline v-if="index > 0" :tone="tone" class="mx-0" />
                <span :class="[index === flexible ? 'min-w-0 truncate' : 'shrink-0 whitespace-nowrap', index < lead ? LEAD : '']">{{ part }}</span>
            </template>
        </template>
    </span>
</template>
