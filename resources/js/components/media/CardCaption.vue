<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import { Link } from '@inertiajs/vue3';

// The text under a poster: a bold title and one muted line beneath it — a
// year, a role, an episode count, the show an episode belongs to. Either line
// becomes a link when it has somewhere to point.
//
// A subtitle made of SEVERAL facts comes as a list, not as a sentence the
// caller punctuated: the hairline rule then sets them apart (`MetaLine`).
withDefaults(
    defineProps<{
        title: string;
        subtitle?: string | string[] | null;
        href?: string | null;
        /** Where the second line points, when that is somewhere of its own. */
        subtitleHref?: string | null;
    }>(),
    { subtitle: null, href: null, subtitleHref: null },
);
</script>

<template>
    <!-- The block is always two lines tall (70px: 12 + 24 + 2 + 20 + 12), so a
         card with nothing to say on the second line does not stand shorter
         than the cards beside it in the grid — its title centres in the space
         instead of leaving a hole under itself. -->
    <div class="xs:min-h-17.5 xs:p-3 flex min-h-15 flex-col justify-center p-2 text-center">
        <component
            :is="href ? Link : 'div'"
            :href="href ?? undefined"
            class="text-ink xs:text-base block truncate text-sm font-bold transition-colors duration-150"
            :class="href ? 'hover:text-brand-soft' : undefined"
        >
            {{ title }}
        </component>
        <component
            :is="subtitleHref ? Link : 'div'"
            v-if="Array.isArray(subtitle) ? subtitle.length : subtitle"
            :href="subtitleHref ?? undefined"
            class="text-ink-muted mt-0.5 block truncate text-sm font-semibold transition-colors duration-150"
            :class="subtitleHref ? 'hover:text-brand-soft' : undefined"
        >
            <MetaLine v-if="Array.isArray(subtitle)" :parts="subtitle" class="justify-center" />
            <template v-else>{{ subtitle }}</template>
        </component>
    </div>
</template>
