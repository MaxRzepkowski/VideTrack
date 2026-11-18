<script setup lang="ts">
import ChartCard from '@/components/statistics/ChartCard.vue';
import MakerPanel from '@/components/statistics/MakerPanel.vue';
import ZoomControls from '@/components/ZoomControls.vue';
import { useZoom } from '@/composables/useZoom';
import { mediaImage } from '@/lib/images';
import { packBubbles, RADIUS_LABELLED, RADIUS_LOGO } from '@/lib/origins';
import type { CreatorKind, MakerTally, RatedTitle } from '@/types/generated';
import { countTitles, typeNoun } from '@/lib/statistics';
import { trans } from 'laravel-vue-i18n';
import { useElementSize } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

/**
 * The networks the period's shows ran on, or the studios that made its
 * titles: a bubble each, its area the number of titles. A bubble large
 * enough holds the maker's logo on paper, or its name where it has none; every
 * one says who it is on hover, and a click puts it in the panel beside them.
 */
const props = defineProps<{
    makers: MakerTally[];
    /** The makers' best titles; a maker's `best` points into it. */
    titles: RatedTitle[];
    kind: CreatorKind;
    type: string | null;
    /** Period and scope, said in the subtitle. */
    period: string;
    scope: string;
}>();

/** The tallest the cluster grows; more makers shrink the bubbles instead. */
const MAX_HEIGHT = 640;
/** Room between the cluster and the edges of its ground. */
const INSET = 24;
/** The column's width until it is measured (and where nothing measures, as in tests). */
const FALLBACK_WIDTH = 900;

const container = ref<HTMLElement | null>(null);
const { width: measured } = useElementSize(container);
const boxWidth = computed(() => measured.value || FALLBACK_WIDTH);
const layout = computed(() => packBubbles(props.makers, { width: boxWidth.value - 2 * INSET, height: MAX_HEIGHT }));
const boxHeight = computed(() => layout.value.height + 2 * INSET);
/** Where the cluster starts on its ground: centred across, inset from the top. */
const offsetX = computed(() => (boxWidth.value - layout.value.width) / 2);
const networks = computed(() => props.kind === 'network');

// Zoom in until the smallest bubble holds its name and count; a cluster where every one already does has nothing to zoom to.
const viewport = ref<HTMLElement | null>(null);
const maxZoom = computed(() => Math.max(1, RADIUS_LABELLED / Math.min(...layout.value.bubbles.map((bubble) => bubble.r), RADIUS_LABELLED)));
const zoom = useZoom(viewport, maxZoom);

watch(layout, zoom.reset);

/** The maker the panel beside the bubbles speaks of: the one clicked last, the largest until then. */
const active = ref(0);
const activeMaker = computed(() => props.makers[active.value] ?? props.makers[0]);

watch(
    () => props.makers,
    () => (active.value = 0),
);

/** The bubbles as drawn at the zoom, less the ones panned out of sight. */
const drawn = computed(() => {
    const scale = zoom.scale.value;

    return layout.value.bubbles
        .map((bubble, index) => ({
            index,
            maker: bubble.maker,
            x: (bubble.x + offsetX.value) * scale + zoom.x.value,
            y: (bubble.y + INSET) * scale + zoom.y.value,
            r: bubble.r * scale,
        }))
        .filter(
            (bubble) =>
                bubble.x + bubble.r > 0 && bubble.x - bubble.r < boxWidth.value && bubble.y + bubble.r > 0 && bubble.y - bubble.r < boxHeight.value,
        );
});

const title = computed(() =>
    trans(networks.value ? 'statistics.makers_networks_title' : 'statistics.makers_studios_title', {
        type: typeNoun(props.type),
    }),
);
const subtitle = computed(() =>
    trans(networks.value ? 'statistics.makers_networks_subtitle' : 'statistics.makers_studios_subtitle', {
        period: props.period,
        scope: props.scope,
    }),
);

const describe = (maker: MakerTally): string =>
    trans('statistics.origins_tooltip', { name: maker.creator.name, titles: countTitles(props.type, maker.titles) });

/** A bubble's logo at the size it is drawn; none on a bubble too small to show one. */
const logoOf = (bubble: { maker: MakerTally; r: number }): string | null =>
    bubble.r < RADIUS_LOGO ? null : mediaImage(bubble.maker.creator.logoPath, bubble.r < RADIUS_LABELLED ? 'w92' : 'w185');

const place = (x: number, y: number, r: number): Record<string, string> => ({
    left: `${x - r}px`,
    top: `${y - r}px`,
    width: `${2 * r}px`,
    height: `${2 * r}px`,
});
</script>

<template>
    <ChartCard :title="title" :subtitle="subtitle">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div ref="container" class="min-w-0">
                <div
                    ref="viewport"
                    class="bg-surface-raised relative w-full overflow-hidden rounded-lg"
                    :class="zoom.zoomed.value ? 'cursor-grab touch-none select-none' : 'touch-pan-y'"
                    :style="{ height: `${boxHeight}px` }"
                    @wheel="zoom.onWheel"
                    @pointerdown="zoom.onPointerdown"
                    @pointermove="zoom.onPointermove"
                    @click.capture="zoom.onClickCapture"
                >
                    <button
                        v-for="bubble in drawn"
                        :key="bubble.index"
                        v-tooltip="describe(bubble.maker)"
                        type="button"
                        :aria-label="describe(bubble.maker)"
                        :aria-pressed="bubble.index === active"
                        class="bg-paper focus-visible:ring-brand-soft absolute flex cursor-pointer items-center justify-center overflow-hidden rounded-full text-center transition-opacity duration-150 hover:opacity-85 focus-visible:ring-4 focus-visible:outline-hidden"
                        :class="bubble.index === active ? 'ring-brand ring-4 ring-inset' : ''"
                        :style="place(bubble.x, bubble.y, bubble.r)"
                        @click="active = bubble.index"
                    >
                        <!-- The content's box is a share of the circle's own: a padding in % would be one of the whole cluster's width. -->
                        <span class="flex size-[72%] min-h-0 flex-col items-center justify-center gap-1">
                            <img
                                v-if="logoOf(bubble)"
                                :src="logoOf(bubble) ?? undefined"
                                alt=""
                                width="185"
                                height="92"
                                loading="lazy"
                                draggable="false"
                                class="h-auto max-h-[45%] min-h-0 w-auto max-w-full object-contain"
                            />
                            <template v-if="bubble.r >= RADIUS_LABELLED">
                                <span
                                    v-if="!bubble.maker.creator.logoPath"
                                    class="text-surface-base line-clamp-3 leading-tight font-semibold"
                                    :class="bubble.index === 0 ? 'text-lg' : 'text-sm'"
                                >
                                    {{ bubble.maker.creator.name }}
                                </span>
                                <span class="text-surface-raised text-sm tabular-nums">{{ countTitles(type, bubble.maker.titles) }}</span>
                            </template>
                        </span>
                    </button>
                    <ZoomControls
                        v-if="maxZoom > 1"
                        class="absolute top-3 right-3"
                        :scale="zoom.scale.value"
                        :max="maxZoom"
                        @in="zoom.zoomIn"
                        @out="zoom.zoomOut"
                        @reset="zoom.reset"
                    />
                </div>
            </div>
            <div v-if="activeMaker" class="flex flex-col gap-4">
                <MakerPanel :maker="activeMaker" :titles="titles" :count="countTitles(type, activeMaker.titles)" />
                <p v-if="makers.length > 1" class="text-ink-faint m-0 text-sm">{{ trans('statistics.makers_click_hint') }}</p>
            </div>
        </div>
    </ChartCard>
</template>
