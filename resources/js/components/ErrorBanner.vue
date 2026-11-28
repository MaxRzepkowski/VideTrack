<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, CircleAlert } from '@lucide/vue';

// Something gone wrong, said once above the page's content: a source that
// failed in part, or what needs the admin, with where to see more.
defineProps<{
    message: string;
    /** Where the rest of it is said, with the word that leads there. */
    link?: { href: string; label: string };
}>();
</script>

<template>
    <!-- A card like the page's others: the icon alone carries the colour, so the
         sentence reads as calmly as the rest of the page. -->
    <div class="border-line bg-surface mb-8 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border px-5 py-4 text-base" role="alert">
        <div class="flex min-w-0 flex-1 items-start gap-3">
            <CircleAlert class="text-danger mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span class="text-ink">{{ message }}</span>
        </div>
        <Link
            v-if="link"
            :href="link.href"
            class="text-ink-secondary hover:text-ink flex shrink-0 items-center gap-1.5 font-medium transition-colors"
        >
            {{ link.label }}
            <ArrowRight class="size-4" aria-hidden="true" />
        </Link>
    </div>
</template>
