<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';

// Something gone wrong, said once above the page's content: a source that
// failed in part, or what needs the admin, with where to see more.
defineProps<{
    message: string;
    /** Where the rest of it is said, with the word that leads there. */
    link?: { href: string; label: string };
}>();
</script>

<template>
    <div
        class="border-danger-line bg-danger-surface text-danger mb-8 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border p-6 text-base"
        role="alert"
    >
        <span>{{ message }}</span>
        <Link v-if="link" :href="link.href" class="flex shrink-0 items-center gap-1.5 font-semibold hover:underline">
            {{ link.label }}
            <ArrowRight class="size-4" aria-hidden="true" />
        </Link>
    </div>
</template>
