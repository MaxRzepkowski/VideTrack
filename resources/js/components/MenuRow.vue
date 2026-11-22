<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

/**
 * A row of a menu or of a dialog: a glyph on a square, the name and what is
 * there (or what it does); a link with `href`, else a button. The
 * colours come from the `--tint` properties of an ancestor (`rowTint()`); a
 * pointed, focused or highlighted row fills the square with its colour.
 *
 * The fill and the dark glyph fade in over the square and the light glyph
 * fades out, rather than the colours sliding: half-way through a slide the
 * glyph's colour meets the square's and the glyph blinks out.
 */
withDefaults(
    defineProps<{
        href?: string;
        icon: LucideIcon;
        label: string;
        description?: string;
        /** How far the row stands in from its container's edges: a menu's panel, or a dialog's body. */
        inset?: 'menu' | 'dialog';
    }>(),
    { inset: 'menu' },
);
</script>

<template>
    <component
        :is="href === undefined ? 'button' : Link"
        :href="href"
        :type="href === undefined ? 'button' : undefined"
        class="group/row hover:bg-surface-overlay focus-visible:bg-surface-overlay focus-visible:outline-ink data-highlighted:bg-surface-overlay flex w-full cursor-pointer items-center gap-3 py-2 text-left transition-colors duration-150 focus-visible:outline-2 focus-visible:-outline-offset-2"
        :class="inset === 'dialog' ? 'px-5' : 'px-3'"
    >
        <span class="bg-surface-overlay relative grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg">
            <span
                class="absolute inset-0 bg-(color:--tint-fill) opacity-0 transition-opacity duration-150 group-hover/row:opacity-100 group-focus-visible/row:opacity-100 group-data-highlighted/row:opacity-100"
                aria-hidden="true"
            />
            <component
                :is="icon"
                class="relative size-5.5 text-(color:--tint) transition-opacity duration-150 [grid-area:1/1] group-hover/row:opacity-0 group-focus-visible/row:opacity-0 group-data-highlighted/row:opacity-0"
            />
            <component
                :is="icon"
                class="relative size-5.5 text-(color:--tint-ink) opacity-0 transition-opacity duration-150 [grid-area:1/1] group-hover/row:opacity-100 group-focus-visible/row:opacity-100 group-data-highlighted/row:opacity-100"
                aria-hidden="true"
            />
        </span>
        <span class="min-w-0 flex-1">
            <span class="text-ink block text-base leading-snug font-semibold">{{ label }}</span>
            <span
                v-if="description"
                class="text-ink-muted group-hover/row:text-ink-secondary group-focus-visible/row:text-ink-secondary group-data-highlighted/row:text-ink-secondary block text-sm transition-colors duration-150"
            >
                {{ description }}
            </span>
        </span>
    </component>
</template>
