<template>
    <!-- The wide "hero" container (1530px): image/cover backdrops
         deliberately span wider than the 1400px content container. Every view
         with such a background uses this wrapper so they all line up. -->
    <div class="max-w-wide mx-auto w-full px-2 md:px-0">
        <slot />
    </div>
</template>
