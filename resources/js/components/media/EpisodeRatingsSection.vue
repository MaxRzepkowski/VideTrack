<script setup lang="ts">
import RatingLegend from '@/components/media/ratings/RatingLegend.vue';
import RatingModeSelect from '@/components/media/ratings/RatingModeSelect.vue';
import RatingTooltip from '@/components/media/ratings/RatingTooltip.vue';
import MetaLine from '@/components/MetaLine.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import { episodeCode, formatCalendarDate } from '@/lib/format';
import { cellAnchor, pointAnchor, ratingScale, type TooltipAnchor } from '@/lib/ratingChart';
import { type RatingTier as Tier, ratingTier as tierOf } from '@/lib/ratings';
import type { EpisodeRating, SeasonRatings } from '@/types/media';
import { useElementSize } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps<{
    seasons: SeasonRatings[];
}>();

const mode = ref<'heatmap' | 'chart'>('heatmap');

// ---- Data prep ----

const plotted = computed(() => props.seasons.filter((season) => season.episodes.some((e) => e.rating !== null)));

const allRatings = computed(() => plotted.value.flatMap((season) => season.episodes.map((e) => e.rating).filter((r): r is number => r !== null)));

const hasData = computed(() => allRatings.value.length >= 2);

// Rating tiers + colours are the shared scale from lib/media (also used by the
// collection-ratings grid), imported above as tierOf / Tier.

// ---- Season averages (the AVG summary row / axis sublabels) ----

function seasonAverage(season: SeasonRatings): number | null {
    const rated = season.episodes.map((e) => e.rating).filter((r): r is number => r !== null);

    if (rated.length === 0) {
        return null;
    }

    return rated.reduce((sum, r) => sum + r, 0) / rated.length;
}

// ---- Heatmap (transposed: seasons are columns, episodes are rows) ----

const maxEpisode = computed(() => Math.max(...plotted.value.map((season) => season.episodes.length)));

interface HeatCell {
    season: number;
    /** null = this season has no such episode. */
    entry: EpisodeRating | null;
    /** null = unrated episode. */
    tier: Tier | null;
    label: string | null;
}

// Shows with absolute episode numbering (One Piece: S2 starts at E62) can't
// have truthful per-row "E{n}" labels — the labels are hidden for them.
const absoluteNumbering = computed(() => plotted.value.some((season) => (season.episodes[0]?.episode ?? 1) !== 1));

const heatRows = computed(() => {
    const rows: { number: number; cells: HeatCell[] }[] = [];

    for (let number = 1; number <= maxEpisode.value; number++) {
        rows.push({
            number,
            cells: plotted.value.map((season) => {
                // Positional, not by episode number, so absolute numbering
                // still fills every column from the top; the tooltip carries
                // the real episode code.
                const entry = season.episodes[number - 1] ?? null;

                return {
                    season: season.season,
                    entry,
                    tier: entry?.rating != null ? tierOf(entry.rating) : null,
                    label: entry?.rating != null ? entry.rating.toFixed(1) : null,
                };
            }),
        });
    }

    return rows;
});

const heatAverages = computed(() =>
    plotted.value.map((season) => {
        const average = seasonAverage(season);

        return {
            season: season.season,
            label: average?.toFixed(1) ?? null,
            tier: average === null ? null : tierOf(average),
        };
    }),
);

// Long shows collapse to the first rows so the section never fills several
// screens; the AVG summary stays visible either way.
const COLLAPSED_ROWS = 12;

const expanded = ref(false);

const visibleRows = computed(() => (expanded.value ? heatRows.value : heatRows.value.slice(0, COLLAPSED_ROWS)));

const hiddenRowCount = computed(() => Math.max(0, heatRows.value.length - COLLAPSED_ROWS));

// Fixed-width columns: a show with too many seasons scrolls horizontally
// instead of squeezing the cells into unreadability. Without row labels
// (absolute numbering) the label column disappears entirely.
const gridStyle = computed(() => ({
    gridTemplateColumns: `${absoluteNumbering.value ? '' : '3rem '}repeat(${plotted.value.length}, 3rem)`,
}));

// ---- Chart geometry: always fits the container, never scrolls ----

const container = ref<HTMLElement | null>(null);
const { width: measuredWidth } = useElementSize(container);
const containerWidth = computed(() => measuredWidth.value || 1000);

const TOP = 14;
const BOTTOM = 56;
const LEFT = 46;
const RIGHT = 8;
const SEASON_GAP = 14;
const PLOT_H = 240;

const scale = computed(() => ratingScale(allRatings.value, TOP, PLOT_H));

interface ChartPoint {
    x: number;
    y: number;
    slotX: number;
    seasonNumber: number;
    entry: EpisodeRating;
}

interface ChartSeason {
    number: number;
    labelX: number;
    average: number | null;
    points: ChartPoint[];
}

const chart = computed(() => {
    const totalSlots = plotted.value.reduce((sum, season) => sum + season.episodes.length, 0);
    const gaps = SEASON_GAP * Math.max(0, plotted.value.length - 1);
    const step = Math.max(1, (containerWidth.value - LEFT - RIGHT - gaps) / Math.max(1, totalSlots));
    const dotRadius = Math.min(4, Math.max(1.5, step * 0.3));

    const seasons: ChartSeason[] = [];
    let x = LEFT;

    for (const season of plotted.value) {
        const startX = x;
        const points: ChartPoint[] = [];

        // Every episode occupies a slot so gaps of unrated episodes stay
        // visible in the line.
        for (const entry of season.episodes) {
            if (entry.rating !== null) {
                points.push({ x: x + step / 2, y: scale.value.yOf(entry.rating), slotX: x, seasonNumber: season.season, entry });
            }

            x += step;
        }

        seasons.push({ number: season.season, labelX: (startX + x) / 2, average: seasonAverage(season), points });
        x += SEASON_GAP;
    }

    return { seasons, step, dotRadius, width: containerWidth.value, height: TOP + PLOT_H + BOTTOM };
});

// ---- Tooltip (shared by both modes; flips below the mark near the top edge) ----

interface EpisodeTip {
    anchor: TooltipAnchor;
    code: string;
    title: string;
    /** null = unrated episode: the star shows "–". */
    rating: number | null;
    airDate: string | null;
}

const tooltip = ref<EpisodeTip | null>(null);
const plotWrap = ref<HTMLElement | null>(null);

function tipOf(anchor: TooltipAnchor, seasonNumber: number, entry: EpisodeRating): EpisodeTip {
    return { anchor, code: episodeCode(seasonNumber, entry.episode), title: entry.title, rating: entry.rating, airDate: entry.airDate };
}

function showPointTooltip(point: ChartPoint): void {
    if (point.entry.rating !== null) {
        tooltip.value = tipOf(pointAnchor(point.x, point.y, chart.value.width), point.seasonNumber, point.entry);
    }
}

function showCellTooltip(event: Event, seasonNumber: number, entry: EpisodeRating): void {
    if (plotWrap.value !== null) {
        const cell = (event.currentTarget as HTMLElement).getBoundingClientRect();

        tooltip.value = tipOf(cellAnchor(cell, plotWrap.value.getBoundingClientRect(), plotWrap.value.scrollWidth), seasonNumber, entry);
    }
}
</script>

<template>
    <section v-if="hasData" class="pt-16">
        <div class="border-line-subtle bg-surface rounded-2xl border p-5">
            <SectionHeading :title="trans('media.episode_ratings')">
                <RatingModeSelect v-model="mode" :label="trans('media.episode_ratings')" />
            </SectionHeading>

            <RatingLegend />

            <div ref="container" class="overflow-x-auto" @mouseleave="tooltip = null">
                <div ref="plotWrap" class="relative w-max min-w-full">
                    <!-- Heatmap: seasons as columns, episodes as rows, AVG summary at the bottom -->
                    <div v-if="mode === 'heatmap'" class="grid justify-start gap-1.5" :style="gridStyle">
                        <div v-if="!absoluteNumbering"></div>
                        <div
                            v-for="season in plotted"
                            :key="`head-${season.season}`"
                            class="text-ink-muted pb-1.5 text-center text-base font-semibold"
                        >
                            S{{ season.season }}
                        </div>

                        <template v-for="row in visibleRows" :key="row.number">
                            <div v-if="!absoluteNumbering" class="text-ink-muted flex items-center justify-end pr-2.5 text-base font-semibold">
                                E{{ row.number }}
                            </div>
                            <template v-for="cell in row.cells" :key="`${cell.season}-${row.number}`">
                                <!-- Focusable buttons so the tooltip (title / date /
                                     rating) is reachable by keyboard and touch, not
                                     only hover. -->
                                <button
                                    v-if="cell.tier && cell.entry"
                                    type="button"
                                    :aria-label="`${cell.entry.title}: ${cell.label}`"
                                    class="hover:ring-ink flex h-8 items-center justify-center rounded-md text-xl font-bold transition-shadow duration-100 hover:ring-2"
                                    :style="{ background: cell.tier.color, color: cell.tier.ink }"
                                    @mouseenter="showCellTooltip($event, cell.season, cell.entry)"
                                    @focus="showCellTooltip($event, cell.season, cell.entry)"
                                    @click="showCellTooltip($event, cell.season, cell.entry)"
                                    @blur="tooltip = null"
                                >
                                    {{ cell.label }}
                                </button>
                                <!-- Existing but unrated episode: a muted placeholder;
                                     a season without this slot stays empty -->
                                <button
                                    v-else-if="cell.entry"
                                    type="button"
                                    :aria-label="cell.entry.title"
                                    class="bg-surface-raised hover:ring-ink/60 text-ink-faint flex h-8 items-center justify-center rounded-md text-base transition-shadow duration-100 hover:ring-2"
                                    @mouseenter="showCellTooltip($event, cell.season, cell.entry)"
                                    @focus="showCellTooltip($event, cell.season, cell.entry)"
                                    @click="showCellTooltip($event, cell.season, cell.entry)"
                                    @blur="tooltip = null"
                                >
                                    –
                                </button>
                                <div v-else></div>
                            </template>
                        </template>

                        <!-- Expand/collapse: centered in the visible viewport even
                             when the grid itself is wider and scrolls -->
                        <ShowMoreToggle
                            v-if="hiddenRowCount > 0"
                            v-model="expanded"
                            :hidden-count="hiddenRowCount"
                            class="sticky left-0 col-span-full mt-2"
                            :style="{ width: `${containerWidth}px` }"
                        />

                        <!-- Season average summary: the value with a tier-colored
                             underline bar (rounded at the top), seriesgraph-style -->
                        <div v-if="!absoluteNumbering" class="text-ink-muted mt-2 flex items-center justify-end pr-2.5 text-base font-bold">
                            {{ trans('media.avg') }}
                        </div>
                        <div v-for="average in heatAverages" :key="`avg-${average.season}`" class="mt-2 flex flex-col items-center gap-1.5">
                            <span class="text-ink text-xl leading-none font-bold">{{ average.label }}</span>
                            <div class="h-1.5 w-full rounded-t-md" :style="average.tier ? { background: average.tier.color } : undefined"></div>
                        </div>
                    </div>

                    <!-- Chart: dots wear the tier color; the line is neutral -->
                    <svg v-else :width="chart.width" :height="chart.height" class="block" role="img" :aria-label="trans('media.episode_ratings')">
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

                        <g v-for="season in chart.seasons" :key="season.number">
                            <polyline
                                v-if="season.points.length > 1"
                                :points="season.points.map((p) => `${p.x},${p.y}`).join(' ')"
                                fill="none"
                                class="stroke-chart-line"
                                stroke-width="2"
                                stroke-linejoin="round"
                                stroke-linecap="round"
                            />
                            <circle
                                v-for="point in season.points"
                                :key="point.entry.episode"
                                :cx="point.x"
                                :cy="point.y"
                                :r="chart.dotRadius"
                                :style="{ fill: tierOf(point.entry.rating ?? 0).color }"
                                class="stroke-surface"
                                :stroke-width="chart.dotRadius > 2.5 ? 2 : 1"
                            />
                            <text :x="season.labelX" :y="TOP + PLOT_H + 24" text-anchor="middle" class="fill-ink-muted text-base font-semibold">
                                S{{ season.number }}
                            </text>
                            <text
                                v-if="season.average !== null"
                                :x="season.labelX"
                                :y="TOP + PLOT_H + 46"
                                text-anchor="middle"
                                class="fill-ink-faint text-base"
                            >
                                {{ season.average.toFixed(1) }}
                            </text>
                            <!-- Full-height hit slots: hovering anywhere in an episode's column finds it -->
                            <rect
                                v-for="point in season.points"
                                :key="`hit-${point.entry.episode}`"
                                :x="point.slotX"
                                :y="TOP"
                                :width="chart.step"
                                :height="PLOT_H"
                                fill="transparent"
                                @mouseenter="showPointTooltip(point)"
                            />
                        </g>
                    </svg>

                    <RatingTooltip v-if="tooltip" :anchor="tooltip.anchor" :rating="tooltip.rating">
                        <MetaLine :parts="[tooltip.code, tooltip.title]" class="text-ink-secondary mt-0.5 text-base" />
                        <div v-if="tooltip.airDate" class="text-ink-muted text-base">{{ formatCalendarDate(tooltip.airDate) }}</div>
                    </RatingTooltip>
                </div>
            </div>
        </div>
    </section>
</template>
