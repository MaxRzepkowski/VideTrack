<script setup lang="ts">
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { MEDIA_TYPE_META } from '@/lib/mediaTypes';
import type { CollectionBannerData } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        collection: CollectionBannerData;
        /** Backdrop to fall back to (the current title's) when the collection has none. */
        fallbackBackdrop?: string | null;
    }>(),
    { fallbackBackdrop: null },
);

const backdrop = computed(() => props.collection.backdropPath ?? props.fallbackBackdrop);

// Said in its parts' own word: "5 movies", "5 games".
const partCount = computed(() =>
    props.collection.partCount ? (MEDIA_TYPE_META[props.collection.partType]?.count(props.collection.partCount) ?? null) : null,
);
</script>

<template>
    <section class="pt-16">
        <Link
            :href="route('collections.show', { provider: collection.provider, ref: collection.ref })"
            class="group border-line-subtle hover:border-brand relative block overflow-hidden rounded-2xl border transition-colors duration-150"
        >
            <img
                v-if="backdrop"
                :src="mediaImage(backdrop, 'w1280') ?? undefined"
                :srcset="mediaImageSrcSet(backdrop, [780, 1280])"
                sizes="(min-width: 768px) 900px, 100vw"
                alt=""
                width="1280"
                height="720"
                loading="lazy"
                class="absolute inset-0 h-full w-full object-cover"
            />
            <div v-else class="bg-surface absolute inset-0"></div>
            <div
                class="absolute inset-0"
                style="
                    background: linear-gradient(
                        to right,
                        color-mix(in oklab, var(--color-surface-base) 92%, transparent) 0%,
                        color-mix(in oklab, var(--color-surface-base) 72%, transparent) 45%,
                        color-mix(in oklab, var(--color-surface-base) 35%, transparent) 100%
                    );
                "
            ></div>

            <div class="relative flex min-h-47.5 flex-col justify-center gap-1.5 p-8 sm:pr-56 md:p-10 md:pr-64">
                <div class="text-brand-soft text-sm font-bold">{{ trans('media.collection') }}</div>
                <div class="text-ink text-2xl font-semibold md:text-3xl">{{ collection.name }}</div>
                <div v-if="partCount" class="text-ink-muted text-sm font-semibold">{{ partCount }}</div>
            </div>

            <span
                class="bg-brand text-on-accent absolute top-1/2 right-8 hidden -translate-y-1/2 rounded-xl px-4 py-2.5 text-sm font-bold transition-opacity duration-150 group-hover:opacity-90 sm:block md:right-10"
            >
                {{ trans('media.view_collection') }} →
            </span>
        </Link>
    </section>
</template>
