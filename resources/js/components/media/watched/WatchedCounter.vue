<script setup lang="ts">
import { parseDecimal, positionText, progressInputMax, progressInputStep } from '@/lib/progress';
import type { MediaSummary } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { BookOpen, Percent } from 'lucide-vue-next';
import { computed, ref, useId, watch } from 'vue';

// The counter of a progress type, as the field the user types it into. The
// model holds the INPUT unit (hours / pages) — the modal converts on submit.
const props = defineProps<{
    media: MediaSummary;
    /** The field holds hours of one game session, which add to the total. */
    adding: boolean;
}>();

const amount = defineModel<string | number>({ required: true });

// Any change the user makes: dragging, typing, or typing a percent.
const emit = defineEmits<{ edited: [] }>();

const fieldId = useId();

const position = computed(() => positionText(props.media));

/** The known total a position counter runs to — shown as "/ 416" beside it. */
const total = computed(() => (position.value ? progressInputMax(props.media) : null));

// A reading type whose total is known (a book's pages, a finished manga's
// chapters) gets a slider: where the scale exists, dragging beats typing. With no total — an ongoing manga, a book
// the provider has no page count for — there is nothing to draw a scale
// against, so the plain field stands alone.
const withSlider = computed(() => (total.value ?? 0) > 0);

// The same position said two ways. Percent is a LENS, never a storage unit:
// whatever is dragged or typed, what gets written is the page / chapter /
// issue number, so the counter survives a provider later
// correcting the total. The lens opens on the position with every title.
const lens = ref<'position' | 'percent'>('position');
const percentMode = computed(() => withSlider.value && lens.value === 'percent');

const positionValue = computed(() => Math.max(0, Math.round(parseDecimal(amount.value) ?? 0)));

/** How much of the rail is filled — the slider paints it as a gradient. */
const sliderFill = computed(() => `${Math.min(100, Math.round((positionValue.value / (total.value || 1)) * 100))}%`);

const sliderValue = computed({
    get: () => positionValue.value,
    set: (value: number) => {
        amount.value = Math.min(total.value ?? 0, Math.max(0, Math.round(value)));
        emit('edited');
    },
});

// The percent field keeps its own model rather than a computed round-trip
// through the position: on a ten-chapter manga a typed "34" would land on
// chapter 3 and snap back to "30" under the cursor mid-keystroke. So the
// percent follows the counter, except when the counter is following it.
const percent = ref<string | number>('');
let followingPercent = false;

watch(
    amount,
    () => {
        if (followingPercent) {
            followingPercent = false;

            return;
        }

        const scale = total.value ?? 0;

        percent.value = scale > 0 ? Math.min(100, Math.round((positionValue.value / scale) * 100)) : '';
    },
    { immediate: true },
);

/** The percent field: the typed number stays put, the counter follows it. */
function applyPercent(): void {
    const scale = total.value ?? 0;
    const typed = parseDecimal(percent.value);

    if (typed === null || scale <= 0) {
        return;
    }

    const next = Math.round((Math.min(100, Math.max(0, typed)) / 100) * scale);

    if (next !== amount.value) {
        followingPercent = true;
        amount.value = next;
    }

    emit('edited');
}

// The field names exactly what it holds. Only two cases differ from "the
// counter": hours of a game session, which are added to it, and the percent
// lens, which says the same position as a share of the whole.
const label = computed(() => {
    if (percentMode.value) {
        return trans('media.percent_complete');
    }

    if (props.adding) {
        return trans('media.hours_in_this_session');
    }

    return position.value?.currentField ?? trans('media.hours_played');
});

const placeholder = computed(() => (position.value ? trans('media.e_g_250') : trans('media.e_g_1_5')));
</script>

<template>
    <!-- Where the total is known the bar is the input: it drags. It always
         carries the position, whichever lens the field below is wearing. -->
    <input
        v-if="withSlider"
        v-model.number="sliderValue"
        type="range"
        min="0"
        :max="total ?? undefined"
        step="1"
        :aria-label="position?.currentField ?? label"
        class="progress-slider mb-1 w-full"
        :style="{ '--slider-fill': sliderFill }"
    />

    <!-- Two ways of saying the same position. Percent is only ever a way of
         typing — the page number is what gets saved. -->
    <div v-if="withSlider && position" class="bg-surface-raised mb-1 flex gap-1 rounded-xl p-1">
        <button
            type="button"
            :aria-pressed="!percentMode"
            class="flex flex-1 items-center justify-center gap-2 rounded-lg px-2 py-2 text-sm font-semibold transition-colors duration-150"
            :class="percentMode ? 'text-ink-muted hover:text-ink-secondary' : 'bg-surface-overlay text-ink'"
            @click="lens = 'position'"
        >
            <BookOpen class="size-4 shrink-0" />
            {{ position.shortUnit }}
        </button>
        <button
            type="button"
            :aria-pressed="percentMode"
            class="flex flex-1 items-center justify-center gap-2 rounded-lg px-2 py-2 text-sm font-semibold transition-colors duration-150"
            :class="percentMode ? 'bg-surface-overlay text-ink' : 'text-ink-muted hover:text-ink-secondary'"
            @click="lens = 'percent'"
        >
            <Percent class="size-4 shrink-0" />
            {{ trans('media.percent') }}
        </button>
    </div>

    <div class="border-line bg-surface-raised flex items-center gap-3 rounded-xl border px-4 py-3">
        <label :for="fieldId" class="text-ink-muted min-w-0 flex-1 truncate text-sm">{{ label }}</label>
        <!-- Two fields, not one with a swapped model: the percent one keeps
             the typed number while the counter follows it, so rounding never
             edits the digits under the cursor. -->
        <input
            v-if="percentMode"
            :id="fieldId"
            v-model="percent"
            type="number"
            min="0"
            max="100"
            step="1"
            placeholder="0"
            class="border-line-strong text-ink focus:border-brand-soft w-20 shrink-0 border-b border-dashed bg-transparent pb-0.5 text-right text-lg font-semibold tabular-nums [color-scheme:dark] focus:outline-none"
            @input="applyPercent"
        />
        <input
            v-else
            :id="fieldId"
            v-model="amount"
            type="number"
            min="0"
            :max="progressInputMax(media) ?? undefined"
            :step="progressInputStep(media)"
            :placeholder="placeholder"
            class="border-line-strong text-ink focus:border-brand-soft w-20 shrink-0 border-b border-dashed bg-transparent pb-0.5 text-right text-lg font-semibold tabular-nums [color-scheme:dark] focus:outline-none"
            @input="emit('edited')"
        />
        <span v-if="percentMode" class="text-ink-muted shrink-0 text-sm tabular-nums">%</span>
        <span v-else-if="total" class="text-ink-muted shrink-0 text-sm tabular-nums">/ {{ total }}</span>
    </div>
</template>
