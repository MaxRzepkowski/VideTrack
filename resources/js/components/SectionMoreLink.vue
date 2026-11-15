<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

/**
 * A section's "go to the full page" link. Every section carries it twice: in
 * its heading from `sm` up, and under its content on a phone, where the
 * heading has no room for it.
 */
defineProps<{
    href: string;
    label: string;
    placement: 'heading' | 'below';
}>();
</script>

<template>
    <Link
        :href="href"
        :class="[
            'text-brand-soft hover:text-brand items-center gap-1.5 text-base font-semibold transition-colors',
            placement === 'heading' ? 'ml-auto hidden sm:inline-flex' : 'mt-5 ml-auto flex w-fit sm:hidden',
        ]"
    >
        {{ label }}
        <ChevronRight class="size-4" :stroke-width="2.5" aria-hidden="true" />
    </Link>
</template>
