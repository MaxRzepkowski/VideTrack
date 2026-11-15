<script setup lang="ts">
/**
 * The block a page opens with: everything above the results — a cover, an
 * identity row, tabs, the page's own title. It carries the page background,
 * which is the point: the control strip below is the only tinted thing up
 * there, so it reads as the one surface you act on rather than as the bottom
 * edge of a larger slab.
 *
 * `PageToolbar variant="band"` follows it as a SIBLING rather than a child: a
 * sticky element only sticks inside its own parent, and nested here it would
 * come unstuck the moment the masthead scrolled past. The strip draws both its
 * own hairlines — this block ends where the page background ends.
 *
 * No container of its own: children bring theirs, because they do not agree on
 * one. A cover lines up with the wide hero container, a title with the content
 * one.
 */
</script>

<template>
    <div class="bg-surface-base w-full"><slot /></div>
</template>
