<script setup lang="ts">
import { formatCalendarDate, formatNumber, todayDateKey } from '@/lib/format';
import { daysBetween, rangeShortcuts } from '@/lib/statistics';
import { CalendarClock } from '@lucide/vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { PopoverClose, PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'radix-vue';
import { computed, ref, watch } from 'vue';

/**
 * Any stretch of days, beside the year axis: ready-made ranges and two date
 * fields. Nothing changes until "Apply", so typing a date does not reload the
 * page under the user. Escape closes it and focus goes back to its button.
 */
const props = defineProps<{
    /** The range on show, if any (YYYY-MM-DD). */
    from: string | null;
    to: string | null;
    /** The day something was first finished (YYYY-MM-DD), the earliest day worth picking. */
    earliest: string | null;
}>();

const emit = defineEmits<{ apply: [from: string, to: string] }>();

const today = todayDateKey();
const open = ref(false);
const from = ref(props.from ?? today);
const to = ref(props.to ?? today);

// Each opening starts from what the page shows, not from what was typed and abandoned.
watch(open, (isOpen) => {
    if (isOpen) {
        from.value = props.from ?? `${today.slice(0, 4)}-01-01`;
        to.value = props.to ?? today;
    }
});

const shortcuts = computed(() => rangeShortcuts(today));

const valid = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(from.value) && /^\d{4}-\d{2}-\d{2}$/.test(to.value));

const summary = computed(() => {
    if (!valid.value) {
        return props.earliest === null ? null : trans('statistics.earliest_completion', { date: formatCalendarDate(props.earliest) ?? '' });
    }

    const length = Math.abs(daysBetween(from.value, to.value)) + 1;
    const days = transChoice('statistics.count_day', length, { count: formatNumber(length) });

    return props.earliest === null
        ? trans('statistics.range_days', { days })
        : trans('statistics.range_days_earliest', { days, date: formatCalendarDate(props.earliest) ?? '' });
});

function pick(shortcutFrom: string, shortcutTo: string): void {
    from.value = shortcutFrom;
    to.value = shortcutTo;
}

function apply(): void {
    if (!valid.value) {
        return;
    }

    open.value = false;
    emit('apply', from.value, to.value);
}

const FIELD =
    'border-line bg-surface-control text-ink focus:border-brand h-11 w-full rounded-lg border px-3 text-base tabular-nums [color-scheme:dark] focus:outline-hidden';
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger
            class="border-line bg-surface text-ink-secondary hover:border-line-strong data-[state=open]:border-brand-soft focus-visible:ring-brand flex h-10 items-center gap-1.5 rounded-lg border pr-3.5 pl-3 text-base font-semibold transition-colors focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <CalendarClock class="text-ink-muted size-4" aria-hidden="true" />
            {{ trans('statistics.range') }}
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="8"
                class="border-line bg-surface-overlay shadow-card z-50 flex w-[22rem] max-w-[calc(100vw-2rem)] flex-col gap-4 rounded-xl border p-5"
            >
                <h2 class="text-ink m-0 text-base font-semibold">{{ trans('statistics.date_range') }}</h2>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="shortcut in shortcuts"
                        :key="shortcut.key"
                        type="button"
                        class="border-line text-ink-secondary hover:border-line-strong hover:text-ink focus-visible:ring-brand rounded-md border px-2.5 py-1.5 text-sm transition-colors focus-visible:ring-2 focus-visible:outline-hidden"
                        @click="pick(shortcut.from, shortcut.to)"
                    >
                        {{ shortcut.label }}
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-ink-muted text-sm">{{ trans('statistics.from') }}</span>
                        <input v-model="from" type="date" :min="earliest ?? undefined" :max="today" :class="FIELD" />
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-ink-muted text-sm">{{ trans('statistics.to') }}</span>
                        <input v-model="to" type="date" :min="earliest ?? undefined" :max="today" :class="FIELD" />
                    </label>
                </div>

                <p v-if="summary" class="text-ink-muted m-0 text-sm">{{ summary }}</p>

                <div class="flex justify-end gap-2">
                    <PopoverClose
                        class="text-ink-secondary hover:bg-surface-raised hover:text-ink focus-visible:ring-brand rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:outline-hidden"
                    >
                        {{ trans('common.cancel') }}
                    </PopoverClose>
                    <button
                        type="button"
                        :disabled="!valid"
                        class="bg-brand text-on-accent hover:bg-brand-strong focus-visible:ring-brand-soft rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:outline-hidden disabled:opacity-50"
                        @click="apply"
                    >
                        {{ trans('statistics.apply') }}
                    </button>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
