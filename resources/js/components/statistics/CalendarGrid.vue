<script setup lang="ts">
import { todayDateKey } from '@/lib/format';
import { calendarWeeks, monthName, weekdayNames } from '@/lib/statisticsCharts';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

/**
 * Days as squares in a wall-calendar grid: a column per week, the user's
 * first weekday on top, month names over the weeks they start in. What a
 * square's colour and tooltip say is the caller's. Wider than its card (a
 * year on a phone), it scrolls sideways and opens on its latest weeks: those
 * up to today, not the empty ones the rest of this year still has.
 */
const props = defineProps<{
    from: string;
    to: string;
    days: Record<string, number>;
    /** The weekday the user's week opens on (ISO: 1 is Monday). */
    firstWeekday: number;
    /** `var(--color-…)` of a day's square. */
    color: (day: string, count: number) => string;
    tooltip: (day: string, count: number) => string;
}>();

const grid = computed(() => calendarWeeks(props.from, props.to, props.days, props.firstWeekday));
const weekdays = computed(() => weekdayNames('short', props.firstWeekday));

const scroller = ref<HTMLElement | null>(null);

/** The last week holding a day up to today. */
const latestWeek = computed(() => {
    const today = todayDateKey();

    return grid.value.weeks.findLastIndex((week) => week.some((cell) => cell.day !== null && cell.day <= today));
});

function showLatest(): void {
    const latest = scroller.value?.querySelector<HTMLElement>('[data-latest-week]');

    if (scroller.value && latest) {
        scroller.value.scrollLeft = latest.offsetLeft + latest.offsetWidth - scroller.value.clientWidth;
    }
}

onMounted(showLatest);
watch(grid, () => nextTick(showLatest));
</script>

<template>
    <div ref="scroller" class="relative overflow-x-auto pb-1" aria-hidden="true">
        <div class="inline-grid grid-cols-[auto_auto]">
            <span class="bg-surface sticky left-0 z-10" />
            <div class="relative h-5">
                <span
                    v-for="month in grid.months"
                    :key="`${month.column}-${month.month}`"
                    class="text-ink-faint absolute top-0 text-sm"
                    :style="{ left: `${month.column * 22}px` }"
                >
                    {{ monthName(month.month) }}
                </span>
            </div>
            <!-- Pinned, so a scrolled map still says its weekdays. -->
            <div class="text-ink-faint bg-surface sticky left-0 z-10 mt-1 grid grid-rows-7 gap-1 pr-2 text-sm leading-[18px]">
                <span v-for="(name, index) in weekdays" :key="name">{{ index % 2 === 0 ? name : '' }}</span>
            </div>
            <div class="mt-1 flex gap-1">
                <div
                    v-for="(week, column) in grid.weeks"
                    :key="column"
                    :data-latest-week="column === latestWeek ? '' : null"
                    class="grid grid-rows-7 gap-1"
                >
                    <span
                        v-for="(cell, row) in week"
                        :key="row"
                        v-tooltip="cell.day === null ? null : tooltip(cell.day, cell.count)"
                        class="block size-[18px] rounded-[3px]"
                        :class="cell.day === null ? 'invisible' : ''"
                        :style="{ backgroundColor: cell.day === null ? undefined : color(cell.day, cell.count) }"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
