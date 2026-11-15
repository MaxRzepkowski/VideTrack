<script setup lang="ts">
import { formatNumber, formatTotalRuntime, formatWatchTime } from '@/lib/format';
import { kindPlural } from '@/lib/mediaTypes';
import { formatUnitAmount, unitGlyph } from '@/lib/progress';
import type { TooltipContent, TooltipRow } from '@/lib/tooltip';
import type { ListStatisticsData, ListUnitTotal } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { Check, Clock3, File, type LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    stats: ListStatisticsData;
}>();

/** One reading of the list: a glyph, a number, and the table behind it. */
type Chip = { key: string; icon: LucideIcon; value: string; label: string; hint: TooltipContent | null };

/**
 * The glyph a unit reads by: a clock for runtime, and every other unit under
 * the glyph of the medium that counts in it — the same vocabulary the type
 * badges use, so the estimated hours wear the gamepad they come from.
 */
function unitIcon(total: ListUnitTotal): LucideIcon {
    if (total.unit !== 'minutes') {
        return unitGlyph(total.unit) ?? Clock3;
    }

    return total.estimated ? (unitGlyph('minutes') ?? Clock3) : Clock3;
}

function unitLabel(total: ListUnitTotal): string {
    switch (total.unit) {
        case 'pages':
            return trans('lists.total_pages');
        case 'chapters':
            return trans('lists.total_chapters');
        case 'issues':
            return trans('lists.total_issues');
        default:
            return total.estimated ? trans('lists.estimated_playtime') : trans('common.total_runtime');
    }
}

/** The chip's own number: durations short ("3d 4h"), counters spelled out. */
function shortAmount(total: ListUnitTotal, amount: number): string {
    if (total.unit !== 'minutes') {
        return formatUnitAmount(total.unit, amount);
    }

    return `${total.estimated ? '≈ ' : ''}${formatTotalRuntime(amount) ?? ''}`;
}

/** The same amount inside a tooltip, where there is room to spell time out. */
function longAmount(total: ListUnitTotal, amount: number): string {
    return total.unit === 'minutes' ? formatWatchTime(amount) : formatUnitAmount(total.unit, amount);
}

// What is not out yet cannot be finished, so the progress runs over the rest.
const countedCount = computed(() => props.stats.trackableCount - props.stats.upcomingCount);

const completionPercent = computed(() => (countedCount.value > 0 ? Math.round((props.stats.completedCount / countedCount.value) * 100) : 0));

const itemChip = computed<Chip>(() => ({
    key: 'items',
    icon: File,
    label: trans('lists.item_count'),
    value: formatNumber(props.stats.itemCount),
    hint:
        props.stats.types.length === 0
            ? null
            : props.stats.types.map((entry) => ({ label: kindPlural(entry.type), value: formatNumber(entry.count) })),
}));

const unitChips = computed<Chip[]>(() =>
    props.stats.totals.map((total) => {
        const rows: TooltipRow[] = [];

        // A game's size is the provider's average completion time, never the
        // hours really played — the tooltip says so rather than letting the
        // number pass for a measurement.
        if (total.estimated) {
            rows.push({ label: trans('lists.estimated'), value: trans('lists.average_completion_time') });
        }

        if (total.remainingAmount > 0) {
            rows.push({ label: trans('common.left_to_finish'), value: longAmount(total, total.remainingAmount) });
        }

        if (total.behindAmount > 0) {
            rows.push({ label: trans('lists.behind_you'), value: longAmount(total, total.behindAmount) });
        }

        // Ongoing manga rarely carry a chapter count, half-known shelves are
        // the norm — so the total says how much of the shelf it speaks for.
        if (total.itemsCounted < total.itemsTotal) {
            rows.push({
                label: trans('lists.counted_from'),
                value: trans('lists.current_of_total_positions', {
                    current: formatNumber(total.itemsCounted),
                    total: formatNumber(total.itemsTotal),
                }),
            });
        }

        return {
            key: `${total.unit}:${total.estimated}`,
            icon: unitIcon(total),
            label: unitLabel(total),
            value: shortAmount(total, total.amount),
            hint: rows,
        };
    }),
);

const progressChip = computed<Chip>(() => {
    const upcoming: TooltipRow[] =
        props.stats.upcomingCount > 0 ? [{ label: trans('lists.not_out_yet_off_progress'), value: formatNumber(props.stats.upcomingCount) }] : [];

    if (countedCount.value === 0) {
        return { key: 'progress', icon: Check, label: trans('common.progress'), value: '–', hint: upcoming.length > 0 ? upcoming : null };
    }

    const rows: TooltipRow[] = [
        {
            label: trans('lists.finished'),
            value: trans('common.current_of_total_percent', {
                current: formatNumber(props.stats.completedCount),
                total: formatNumber(countedCount.value),
                percent: formatNumber(completionPercent.value),
            }),
        },
    ];

    if (props.stats.inProgressCount > 0) {
        rows.push({ label: trans('common.in_progress'), value: formatNumber(props.stats.inProgressCount) });
    }

    // Named rather than left as a silent gap: without it a list one can never
    // finish reads as a percentage stuck for no visible reason.
    if (props.stats.droppedCount > 0) {
        rows.push({ label: trans('lists.dropped_items'), value: formatNumber(props.stats.droppedCount) });
    }

    // Said where the percentage is read, so a number that leaves titles out
    // says which ones.
    rows.push(...upcoming);

    return {
        key: 'progress',
        icon: Check,
        label: trans('common.progress'),
        value: `${formatNumber(completionPercent.value)}%`,
        hint: rows,
    };
});

const chips = computed<Chip[]>(() => [itemChip.value, ...unitChips.value, progressChip.value]);
</script>

<template>
    <dl class="flex flex-wrap items-center gap-x-5 gap-y-2 font-medium">
        <div
            v-for="chip in chips"
            :key="chip.key"
            v-tooltip="chip.hint"
            data-tooltip-tap
            :tabindex="chip.hint ? 0 : undefined"
            class="focus-visible:ring-ink-muted flex items-center gap-1.5 rounded-sm focus-visible:ring-2 focus-visible:outline-hidden"
            :class="chip.hint ? 'cursor-pointer' : ''"
        >
            <dt class="sr-only">{{ chip.label }}</dt>
            <component :is="chip.icon" class="text-ink-faint size-4.5" :stroke-width="1.7" aria-hidden="true" />
            <dd class="text-ink-secondary tabular-nums">{{ chip.value }}</dd>
        </div>
    </dl>
</template>
