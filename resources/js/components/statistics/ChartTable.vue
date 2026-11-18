<script setup lang="ts">
/**
 * A chart's figures as a table only screen readers get; the drawing beside it
 * is decorative. Hidden through a box around it: a table ignores the width
 * `sr-only` gives it, so on its own it would stick out and widen the page.
 */
</script>

<template>
    <div class="sr-only">
        <table>
            <slot />
        </table>
    </div>
</template>
