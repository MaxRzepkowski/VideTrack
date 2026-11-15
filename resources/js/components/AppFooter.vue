<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

const year = new Date().getFullYear();
</script>

<template>
    <footer class="border-t">
        <!-- Below `sm` the three parts stack and centre: a row that wraps
             wherever the widths land reads as a mistake, a column reads as a
             footer. -->
        <div
            class="max-w-content mx-auto flex w-full flex-col items-center justify-between gap-4 px-4 py-7 text-center sm:flex-row sm:flex-wrap sm:text-left md:px-10"
        >
            <!-- `items-baseline`, not `items-center`: the wordmark's baseline
                 sits at 26.08 of a 27-unit viewBox, so centring the two boxes
                 dropped it ~1.5px under the caption's baseline. Aligning on the
                 baseline puts the img's bottom edge on the text baseline, which
                 lands the wordmark within half a pixel of it.
                 `h-4.25` matches cap heights: the logo's caps are 0.665 of its
                 height (17.95 of 27 units), so 17px ≈ the 11.4px caps of
                 `text-base` in Roboto Flex. -->
            <div class="flex flex-col items-center gap-3 sm:flex-row sm:items-baseline sm:gap-6">
                <img src="/logo.svg" alt="VideTrack" width="162" height="27" loading="lazy" class="block h-4.25 w-auto" />
                <span class="text-ink-muted text-base leading-none">{{ trans('app.year_all_rights_reserved', { year: String(year) }) }}</span>
            </div>
            <div class="flex items-center gap-6">
                <Link :href="route('about')" class="text-ink-muted hover:text-ink text-base transition-colors duration-150">
                    {{ trans('common.about') }}
                </Link>
            </div>
        </div>
        <!-- Decorative closing band, full-bleed. `brand/0` rather than
             `transparent` at the ends so the ramp stays purple the whole way:
             `transparent` is black at zero alpha, and any renderer that doesn't
             interpolate premultiplied would pull the fade toward grey. -->
        <div class="from-brand/0 via-brand to-brand/0 h-1.25 w-full bg-gradient-to-r" aria-hidden="true"></div>
    </footer>
</template>
