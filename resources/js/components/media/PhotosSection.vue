<script setup lang="ts">
import SectionHeading from '@/components/SectionHeading.vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { lightboxOrigin, useLightbox, type LightboxOrigin } from '@/composables/useLightbox';
import { withCount } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { trans } from 'laravel-vue-i18n';
import { ChevronLeft, ChevronRight, X } from '@lucide/vue';
import { DialogClose, DialogContent as DialogContentRaw, DialogOverlay, DialogPortal, DialogRoot, DialogTitle as DialogTitleRaw } from 'radix-vue';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        backdrops: string[];
        posters: string[];
        logos?: string[];
        // Label for the backdrops tab — episodes carry stills, so they override
        // "Backdrops" with "Photos".
        backdropsLabel?: string;
        // Person pages open the same gallery from a quiet text link instead of
        // occupying the page with a preview grid.
        trigger?: 'previews' | 'link';
        postersLabel?: string;
    }>(),
    { logos: () => [], backdropsLabel: undefined, trigger: 'previews', postersLabel: undefined },
);

type GalleryTab = 'Backdrops' | 'Posters' | 'Logos';

function collectionFor(tab: GalleryTab): string[] {
    return tab === 'Backdrops' ? props.backdrops : tab === 'Posters' ? props.posters : props.logos;
}

const total = computed(() => props.backdrops.length + props.posters.length + props.logos.length);

// Up to 7 backdrop previews and a "+N" tile opening the tabbed modal. With the
// tile there, the previews stop where it closes a whole row (3 or 7, four to a
// row): a lone tile under a full row reads as left behind.
const PREVIEW_MAX = 7;
const PER_ROW = 4;

const preview = computed(() => {
    const shown = Math.min(props.backdrops.length, PREVIEW_MAX);

    if (shown === total.value || shown < PER_ROW - 1) {
        return props.backdrops.slice(0, shown);
    }

    return props.backdrops.slice(0, shown - ((shown + 1) % PER_ROW));
});
const remaining = computed(() => total.value - preview.value.length);

// Only the non-empty collections get a tab.
const tabs = computed<GalleryTab[]>(() => (['Backdrops', 'Posters', 'Logos'] as const).filter((tab) => collectionFor(tab).length > 0));

const tabLabels = computed<Record<GalleryTab, string>>(() => ({
    Backdrops: props.backdropsLabel ?? trans('media.backdrops'),
    Posters: props.postersLabel ?? trans('media.posters'),
    Logos: trans('media.logos'),
}));

const galleryOpen = ref(false);
const galleryTab = ref<GalleryTab>('Backdrops');
const galleryItems = computed(() => collectionFor(galleryTab.value));

// Lightbox over a collection so the arrows (and ←/→) cycle within the set the
// image was opened from. A slide opens on the size its tile already loaded,
// in the shape of the set it comes from, so neither waits nor jumps.
const lightbox = useLightbox();
const lightboxOpen = computed(() => lightbox.current.value !== null);

const FROM_PREVIEW = lightboxOrigin('w780', 'landscape');

function fromGallery(tab: GalleryTab): LightboxOrigin {
    return lightboxOrigin('w500', tab === 'Posters' ? 'poster' : 'landscape');
}

function openGallery(): void {
    galleryTab.value = tabs.value[0] ?? 'Backdrops';
    galleryOpen.value = true;
}
</script>

<template>
    <section v-if="total > 0">
        <button
            v-if="trigger === 'link'"
            type="button"
            class="text-brand-soft hover:text-brand text-base font-semibold underline decoration-current/40 underline-offset-4 transition-colors duration-150"
            @click="openGallery"
        >
            {{ trans('media.photos') }}
        </button>

        <template v-else>
            <SectionHeading :title="trans('media.photos')" />

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <button
                    v-for="path in preview"
                    :key="path"
                    type="button"
                    class="border-line-subtle block aspect-video w-full overflow-hidden rounded-lg border transition-opacity duration-150 hover:opacity-80"
                    @click="lightbox.open(backdrops, path, FROM_PREVIEW)"
                >
                    <img
                        :src="mediaImage(path, 'w780') ?? undefined"
                        :srcset="mediaImageSrcSet(path, [342, 780])"
                        sizes="(min-width: 1024px) 25vw, 50vw"
                        alt=""
                        width="780"
                        height="439"
                        loading="lazy"
                        class="block h-full w-full object-cover"
                    />
                </button>
                <button
                    v-if="remaining > 0"
                    type="button"
                    class="border-line-subtle bg-surface-raised hover:border-brand relative flex aspect-video items-center justify-center overflow-hidden rounded-lg border transition-colors duration-150"
                    @click="openGallery"
                >
                    <span class="text-ink text-lg font-extrabold">+{{ remaining }}</span>
                </button>
            </div>
        </template>

        <Dialog v-model:open="galleryOpen">
            <DialogContent class="border-line-subtle bg-surface max-w-panel flex max-h-[85dvh] flex-col rounded-2xl p-4 sm:p-7">
                <DialogTitle class="shrink-0 pr-10 text-2xl font-semibold">{{ trans('media.photos') }}</DialogTitle>
                <div class="flex shrink-0 items-center gap-2">
                    <button
                        v-for="tab in tabs"
                        :key="tab"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors duration-150"
                        :class="galleryTab === tab ? 'bg-brand text-on-accent' : 'bg-surface-raised text-ink-muted'"
                        :aria-pressed="galleryTab === tab"
                        @click="galleryTab = tab"
                    >
                        {{ withCount(tabLabels[tab], collectionFor(tab).length) }}
                    </button>
                </div>
                <div class="overflow-y-auto">
                    <div class="grid gap-3" :class="galleryTab === 'Posters' ? 'grid-cols-3 lg:grid-cols-4' : 'grid-cols-2 lg:grid-cols-3'">
                        <button
                            v-for="path in galleryItems"
                            :key="path"
                            type="button"
                            class="border-line-subtle block w-full overflow-hidden rounded-lg border transition-opacity duration-150 hover:opacity-80"
                            :class="galleryTab === 'Posters' ? 'aspect-poster' : 'aspect-video'"
                            @click="lightbox.open(galleryItems, path, fromGallery(galleryTab))"
                        >
                            <img
                                :src="mediaImage(path, 'w500') ?? undefined"
                                alt=""
                                width="500"
                                height="750"
                                loading="lazy"
                                class="block h-full w-full"
                                :class="galleryTab === 'Logos' ? 'bg-surface-base object-contain p-4' : 'object-cover'"
                            />
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Fullscreen lightbox on the radix primitives directly (Escape,
             focus-trap, backdrop-click); arrows / ←→ cycle the collection.
             The ui/dialog wrapper is centred + max-w-lg, wrong for this. -->
        <DialogRoot
            :open="lightboxOpen"
            @update:open="
                (open) => {
                    if (!open) lightbox.close();
                }
            "
        >
            <DialogPortal>
                <DialogOverlay class="bg-scrim/90 fixed inset-0 z-50" />
                <DialogContentRaw
                    class="fixed inset-0 z-50 flex items-center justify-center p-4 focus:outline-none sm:p-10"
                    @click.self="lightbox.close()"
                    @keydown.left="lightbox.step(-1)"
                    @keydown.right="lightbox.step(1)"
                >
                    <DialogTitleRaw class="sr-only">{{ trans('media.photos') }}</DialogTitleRaw>

                    <!-- Two layers: the thumbnail the tile already loaded
                         answers the click at once, and the full image, keyed
                         by its path so the last slide never lingers, paints
                         over it as it arrives. The full image sizes the frame;
                         the thumbnail fills it, contained the same way. -->
                    <div class="relative overflow-hidden rounded-xl">
                        <img
                            :key="`thumb-${lightbox.current.value}`"
                            :src="lightbox.thumbSrc.value ?? undefined"
                            alt=""
                            :width="lightbox.origin.value.width"
                            :height="lightbox.origin.value.height"
                            loading="lazy"
                            class="absolute inset-0 h-full w-full object-contain"
                        />
                        <img
                            :key="`full-${lightbox.current.value}`"
                            :src="lightbox.fullSrc.value ?? undefined"
                            alt=""
                            :width="lightbox.origin.value.width"
                            :height="lightbox.origin.value.height"
                            loading="lazy"
                            class="relative max-h-[92dvh] max-w-[92vw] object-contain"
                        />
                    </div>

                    <template v-if="lightbox.items.value.length > 1">
                        <button
                            v-tooltip="trans('common.previous')"
                            type="button"
                            class="border-line bg-surface/70 hover:bg-surface fixed top-1/2 left-3 flex size-12 -translate-y-1/2 items-center justify-center rounded-full border transition-colors duration-150"
                            @click="lightbox.step(-1)"
                        >
                            <ChevronLeft class="text-ink size-6" />
                        </button>
                        <button
                            v-tooltip="trans('common.next')"
                            type="button"
                            class="border-line bg-surface/70 hover:bg-surface fixed top-1/2 right-3 flex size-12 -translate-y-1/2 items-center justify-center rounded-full border transition-colors duration-150"
                            @click="lightbox.step(1)"
                        >
                            <ChevronRight class="text-ink size-6" />
                        </button>
                    </template>

                    <DialogClose
                        v-tooltip="trans('common.close')"
                        class="border-line bg-surface/70 hover:bg-surface fixed top-4 right-4 flex size-11 items-center justify-center rounded-full border transition-colors duration-150"
                    >
                        <X class="text-ink size-5" />
                    </DialogClose>
                </DialogContentRaw>
            </DialogPortal>
        </DialogRoot>
    </section>
</template>
