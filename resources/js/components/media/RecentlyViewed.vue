<script setup lang="ts">
import SectionHeading from '@/components/SectionHeading.vue';
import { splitAtPlaceholder } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { MEDIA_TYPE_META } from '@/lib/mediaTypes';
import { mediaUrl } from '@/lib/urls';
import type { MediaSummary } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ArrowUp } from '@lucide/vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** The current item (shown in the banner). */
        title: string;
        type: string;
        backdropPath?: string | null;
        logoPath?: string | null;
        /** Background color when there is no backdrop (books' coverColor, a type tint…). */
        color?: string | null;
        /** Previously viewed items (already excludes the current one). */
        items: MediaSummary[];
    }>(),
    { backdropPath: null, logoPath: null, color: null },
);

// Type tint used when a title has neither a backdrop nor an explicit color, so
// the banner always has a background (with a scrim for readability).

const backdrop = computed(() => mediaImage(props.backdropPath, 'w1280'));
const logo = computed(() => mediaImage(props.logoPath, 'w342'));
const fallbackColor = computed(() => props.color ?? MEDIA_TYPE_META[props.type]?.color ?? 'var(--color-line)');

function backToTop(): void {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// One sentence for the translator, split where the title sits.
const viewing = computed(() => splitAtPlaceholder(trans('media.currently_viewing_title'), ':title'));
</script>

<template>
    <section>
        <!-- Banner: the current item over its backdrop (or a color), with the
             title logo when available and a back-to-top shortcut. Always shown,
             even before there is any history to list below. Below md it stacks
             into a centered column (the logo drops out to save height). -->
        <div class="border-line-subtle relative min-h-45 w-full overflow-hidden rounded-2xl border md:h-45">
            <img
                v-if="backdrop"
                :src="backdrop"
                :srcset="mediaImageSrcSet(backdropPath, [780, 1280])"
                sizes="100vw"
                alt=""
                width="1280"
                height="720"
                loading="lazy"
                class="absolute inset-0 h-full w-full object-cover"
            />
            <div v-else class="absolute inset-0" :style="{ background: `linear-gradient(120deg, ${fallbackColor}, var(--color-surface))` }"></div>

            <!-- Scrim: darker at the edges so the logo and button stay legible. -->
            <div
                class="absolute inset-0"
                style="
                    background: linear-gradient(
                        90deg,
                        color-mix(in oklab, var(--color-surface-base) 80%, transparent) 0%,
                        color-mix(in oklab, var(--color-surface-base) 25%, transparent) 45%,
                        color-mix(in oklab, var(--color-surface-base) 70%, transparent) 100%
                    );
                "
            ></div>

            <div
                class="relative flex h-full flex-col items-center justify-center gap-3 px-4 py-5 text-center md:flex-row md:gap-6 md:px-10 md:py-0 md:text-left"
            >
                <img
                    v-if="logo"
                    :src="logo"
                    :alt="title"
                    width="342"
                    height="96"
                    loading="lazy"
                    class="drop-shadow-float hidden max-h-24 w-auto max-w-2/5 shrink-0 object-contain md:block"
                />
                <!-- With a logo (TMDB) the label sits centered next to it;
                     without one it reads from the left. -->
                <div class="min-w-0 md:flex-1" :class="logo ? 'md:text-center' : 'md:text-left'">
                    <span class="text-ink drop-shadow-tight text-base font-semibold md:text-lg">
                        {{ viewing.before }}<span class="text-on-photo font-bold">{{ title }}</span
                        >{{ viewing.after }}
                    </span>
                </div>
                <button
                    type="button"
                    class="text-surface-base bg-paper shadow-scrim/30 hover:bg-paper flex shrink-0 items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold shadow-lg transition-colors duration-150"
                    @click="backToTop"
                >
                    <ArrowUp class="size-4" :stroke-width="2.4" />
                    {{ trans('media.back_to_top') }}
                </button>
            </div>
        </div>

        <template v-if="items.length">
            <SectionHeading :title="trans('common.recently_viewed')" class="mt-8" />
            <div class="grid grid-cols-3 gap-4 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
                <Link v-for="item in items" :key="`${item.type}:${item.ref}`" :href="mediaUrl(item)" class="group block">
                    <div
                        class="border-line-subtle bg-surface-raised group-hover:border-brand aspect-poster w-full overflow-hidden rounded-xl border transition-colors duration-150"
                    >
                        <img
                            v-if="mediaImage(item.posterPath, 'w342')"
                            :src="mediaImage(item.posterPath, 'w342') ?? undefined"
                            :srcset="mediaImageSrcSet(item.posterPath, [185, 342])"
                            sizes="(min-width: 768px) 12vw, (min-width: 640px) 25vw, 33vw"
                            :alt="item.title"
                            width="342"
                            height="513"
                            loading="lazy"
                            class="h-full w-full object-cover"
                        />
                        <div v-else class="from-surface-raised to-line h-full w-full bg-gradient-to-br"></div>
                    </div>
                    <div
                        v-tooltip.truncated="item.title"
                        class="text-ink-secondary group-hover:text-ink mt-2 truncate text-sm font-semibold transition-colors duration-150"
                    >
                        {{ item.title }}
                    </div>
                </Link>
            </div>
        </template>
    </section>
</template>
