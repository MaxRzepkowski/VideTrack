<script setup lang="ts">
import RatingLegend from '@/components/media/ratings/RatingLegend.vue';
import RatingModeSelect from '@/components/media/ratings/RatingModeSelect.vue';
import RatingTooltip from '@/components/media/ratings/RatingTooltip.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import { formatCalendarDate } from '@/lib/format';
import { cellAnchor, pointAnchor, ratingScale, type TooltipAnchor } from '@/lib/ratingChart';
import { type RatingTier as Tier, ratingTier as tierOf } from '@/lib/ratings';
import type { MediaSummary } from '@/types/media';
import { useElementSize } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps<{
    /** The collection's parts: films of a saga, games or books of a series. */
    parts: MediaSummary[];
}>();

const mode = ref<'heatmap' | 'chart'>('heatmap');

// ---- Data prep ----

// A collection is one level (no season → episode), so the films line up on a
// single chronological axis; the heatmap simply wraps. Sort by release date
// (falling back to year, then title) so the strip reads left→right in order.
// Undated films (no premiere yet) sort LAST — their empty tiles trail the end,
// never lead it.
function sortKey(movie: MediaSummary): string | null {
    return movie.releaseDate ?? (movie.year !== null ? `${movie.year}` : null);
}

const ordered = computed(() =>
    [...props.parts].sort((a, b) => {
        const ka = sortKey(a);
        const kb = sortKey(b);

        if (ka === kb) {
            return a.title.localeCompare(b.title);
        }

        if (ka === null) {
            return 1;
        }

        if (kb === null) {
            return -1;
        }

        return ka.localeCompare(kb);
    }),
);

const allRatings = computed(() => ordered.value.map((m) => m.communityRating).filter((r): r is number => r !== null));

const hasData = computed(() => allRatings.value.length >= 2);

const average = computed<{ label: string; tier: Tier } | null>(() => {
    if (allRatings.value.length === 0) {
        return null;
    }

    const value = allRatings.value.reduce((sum, r) => sum + r, 0) / allRatings.value.length;

    return { label: value.toFixed(1), tier: tierOf(value) };
});

// ---- Heatmap cells (one per film, wrapping) ----

interface Cell {
    movie: MediaSummary;
    tier: Tier | null;
    label: string | null;
}

const cells = computed<Cell[]>(() =>
    ordered.value.map((movie) => ({
        movie,
        tier: movie.communityRating !== null ? tierOf(movie.communityRating) : null,
        label: movie.communityRating !== null ? movie.communityRating.toFixed(1) : null,
    })),
);

// ---- Chart geometry: always fits the container, never scrolls ----

const container = ref<HTMLElement | null>(null);
const { width: measuredWidth } = useElementSize(container);
const containerWidth = computed(() => measuredWidth.value || 1000);

const TOP = 14;
const BOTTOM = 40;
const LEFT = 40;
const RIGHT = 8;
const PLOT_H = 220;

const scale = computed(() => ratingScale(allRatings.value, TOP, PLOT_H));

interface ChartPoint {
    x: number;
    y: number;
    slotX: number;
    movie: MediaSummary;
}

const chart = computed(() => {
    const total = Math.max(1, ordered.value.length);
    const step = Math.max(1, (containerWidth.value - LEFT - RIGHT) / total);
    const dotRadius = Math.min(5, Math.max(2, step * 0.28));

    const points: ChartPoint[] = [];
    let x = LEFT;

    // Every film keeps a slot so gaps of unrated (upcoming) films stay visible.
    for (const movie of ordered.value) {
        if (movie.communityRating !== null) {
            points.push({ x: x + step / 2, y: scale.value.yOf(movie.communityRating), slotX: x, movie });
        }

        x += step;
    }

    return { points, step, dotRadius, width: containerWidth.value, height: TOP + PLOT_H + BOTTOM };
});

// ---- Tooltip (shared by both modes; flips below the mark near the top edge) ----

const tooltip = ref<{ anchor: TooltipAnchor; movie: MediaSummary } | null>(null);
const plotWrap = ref<HTMLElement | null>(null);

function showPointTooltip(point: ChartPoint): void {
    tooltip.value = { anchor: pointAnchor(point.x, point.y, chart.value.width), movie: point.movie };
}

function showCellTooltip(event: Event, movie: MediaSummary): void {
    if (plotWrap.value !== null) {
        const cell = (event.currentTarget as HTMLElement).getBoundingClientRect();

        tooltip.value = { anchor: cellAnchor(cell, plotWrap.value.getBoundingClientRect(), plotWrap.value.clientWidth), movie };
    }
}
</script>

<template>
    <section v-if="hasData" class="mb-8">
        <div class="border-line-subtle bg-surface rounded-2xl border p-5">
            <SectionHeading :title="trans('media.collection_ratings')">
                <div class="flex items-center gap-4">
                    <!-- Overall community-rating average, tier-coloured underline -->
                    <div v-if="average" class="flex items-center gap-2">
                        <span class="text-ink-muted text-sm font-semibold">{{ trans('media.avg') }}</span>
                        <span class="text-ink text-xl leading-none font-bold">{{ average.label }}</span>
                        <span class="h-4 w-1.5 rounded-full" :style="{ background: average.tier.color }"></span>
                    </div>
                    <RatingModeSelect v-model="mode" :label="trans('media.collection_ratings')" />
                </div>
            </SectionHeading>

            <RatingLegend />

            <div ref="container" @mouseleave="tooltip = null">
                <div ref="plotWrap" class="relative">
                    <!-- Heatmap: one tile per film with its year caption, wrapping -->
                    <div v-if="mode === 'heatmap'" class="flex flex-wrap gap-x-2 gap-y-3">
                        <div v-for="cell in cells" :key="`${cell.movie.type}-${cell.movie.ref}`" class="flex w-14 flex-col items-center gap-1">
                            <!-- Focusable buttons so the tooltip is reachable by keyboard and touch, not only hover. -->
                            <button
                                v-if="cell.tier"
                                type="button"
                                :aria-label="`${cell.movie.title}: ${cell.label}`"
                                class="hover:ring-ink flex h-9 w-full items-center justify-center rounded-md text-lg font-bold transition-shadow duration-100 hover:ring-2"
                                :style="{ background: cell.tier.color, color: cell.tier.ink }"
                                @mouseenter="showCellTooltip($event, cell.movie)"
                                @focus="showCellTooltip($event, cell.movie)"
                                @click="showCellTooltip($event, cell.movie)"
                                @blur="tooltip = null"
                            >
                                {{ cell.label }}
                            </button>
                            <!-- Unrated (usually upcoming) film: muted placeholder tile -->
                            <button
                                v-else
                                type="button"
                                :aria-label="cell.movie.title"
                                class="bg-surface-raised hover:ring-ink/60 text-ink-faint flex h-9 w-full items-center justify-center rounded-md text-base transition-shadow duration-100 hover:ring-2"
                                @mouseenter="showCellTooltip($event, cell.movie)"
                                @focus="showCellTooltip($event, cell.movie)"
                                @click="showCellTooltip($event, cell.movie)"
                                @blur="tooltip = null"
                            >
                                –
                            </button>
                            <span class="text-ink-muted text-sm">{{ cell.movie.year ?? trans('common.n_a') }}</span>
                        </div>
                    </div>

                    <!-- Chart: a single rating trajectory across the collection -->
                    <svg v-else :width="chart.width" :height="chart.height" class="block" role="img" :aria-label="trans('media.collection_ratings')">
                        <g v-for="value in scale.grid" :key="value">
                            <line
                                :x1="LEFT - 6"
                                :y1="scale.yOf(value)"
                                :x2="chart.width - RIGHT"
                                :y2="scale.yOf(value)"
                                class="stroke-line"
                                stroke-width="1"
                            />
                            <text :x="LEFT - 12" :y="scale.yOf(value) + 5.5" text-anchor="end" class="fill-ink-muted text-base">{{ value }}</text>
                        </g>

                        <polyline
                            v-if="chart.points.length > 1"
                            :points="chart.points.map((p) => `${p.x},${p.y}`).join(' ')"
                            fill="none"
                            class="stroke-chart-line"
                            stroke-width="2"
                            stroke-linejoin="round"
                            stroke-linecap="round"
                        />
                        <g v-for="point in chart.points" :key="`${point.movie.type}-${point.movie.ref}`">
                            <circle
                                :cx="point.x"
                                :cy="point.y"
                                :r="chart.dotRadius"
                                :style="{ fill: tierOf(point.movie.communityRating ?? 0).color }"
                                class="stroke-surface"
                                :stroke-width="chart.dotRadius > 2.5 ? 2 : 1"
                            />
                            <!-- Year sublabels only when the columns are wide enough to read -->
                            <text
                                v-if="chart.step >= 34 && point.movie.year !== null"
                                :x="point.x"
                                :y="TOP + PLOT_H + 24"
                                text-anchor="middle"
                                class="fill-ink-muted text-base"
                            >
                                {{ point.movie.year }}
                            </text>
                            <!-- Full-height hit slot: hovering anywhere in a film's column finds it -->
                            <rect
                                :x="point.slotX"
                                :y="TOP"
                                :width="chart.step"
                                :height="PLOT_H"
                                fill="transparent"
                                @mouseenter="showPointTooltip(point)"
                            />
                        </g>
                    </svg>

                    <RatingTooltip v-if="tooltip" :anchor="tooltip.anchor" :rating="tooltip.movie.communityRating">
                        <div class="text-ink-secondary mt-0.5 text-base">
                            {{ tooltip.movie.title }}<template v-if="tooltip.movie.year"> ({{ tooltip.movie.year }})</template>
                        </div>
                        <div v-if="tooltip.movie.releaseDate" class="text-ink-muted text-base">
                            {{ formatCalendarDate(tooltip.movie.releaseDate) }}
                        </div>
                    </RatingTooltip>
                </div>
            </div>
        </div>
    </section>
</template>
