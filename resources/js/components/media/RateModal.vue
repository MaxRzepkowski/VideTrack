<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import MenuRow from '@/components/MenuRow.vue';
import AppModal from '@/components/modal/AppModal.vue';
import { shownState, stateKey, useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { useMediaQuery } from '@/composables/useMediaQuery';
import { RATING_MAX, RATING_MIN, ratingAt, ratingScore } from '@/lib/ratings';
import { rowTint } from '@/lib/ui';
import { trans } from 'laravel-vue-i18n';
import { Star, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const { modals, close } = useMediaModals();
const { rate } = useMediaActions();

const media = computed(() => modals.rate);
const state = computed(() => (media.value ? shownState(stateKey(media.value)) : null));

// Ten stars on every screen. A mouse previews the score it rests on and one
// click rates, the preview having said what it gives. A finger has no
// preview and misses easily, so a touch only chooses (a tap, or a slide
// across the stars) and the save button rates. Which one it was is read off
// each event, so a laptop with a touch screen gets both.
const touchScreen = useMediaQuery('(any-pointer: coarse)');
const hovered = ref<number | null>(null);
const chosen = ref<number | null>(null);
const shown = computed(() => hovered.value ?? chosen.value ?? state.value?.rating ?? 0);
/** The score the pointer rests on, or the finger chose. */
const previewed = computed(() => hovered.value ?? chosen.value);
const changed = computed(() => chosen.value !== null && chosen.value !== state.value?.rating);

// At once as well: the modal mounts the first time it is asked for, already open.
watch(
    media,
    () => {
        hovered.value = null;
        chosen.value = null;
    },
    { immediate: true },
);

const byFinger = (event: PointerEvent): boolean => event.pointerType === 'touch' || event.pointerType === 'pen';

function pick(score: number): void {
    if (media.value) {
        rate(media.value, score);
        close();
    }
}

/** A star clicked: a mouse (or a key) rates; a finger only chooses. */
function press(star: number, event: MouseEvent): void {
    if (event instanceof PointerEvent && byFinger(event)) {
        chosen.value = star;
    } else {
        pick(star);
    }
}

/** A finger on the row: the star under it is the choice, as it slides. */
function slide(event: PointerEvent): void {
    if (!byFinger(event) || (event.type === 'pointermove' && event.buttons === 0)) {
        return;
    }

    const row = (event.currentTarget as HTMLElement).getBoundingClientRect();

    chosen.value = ratingAt(event.clientX - row.left, row.width);
}

function save(): void {
    if (chosen.value !== null) {
        pick(chosen.value);
    }
}

function remove(): void {
    if (media.value) {
        rate(media.value, null);
        close();
    }
}
</script>

<template>
    <AppModal :open="media !== null" :title="media?.title ?? ''" @update:open="(open: boolean) => !open && close()">
        <template v-if="state" #meta>
            <template v-if="state.rating !== null">
                <span class="text-ink-muted">{{ trans('media.your_rating') }}</span>
                <Hairline class="mx-0" />
                <span class="text-action-rating font-medium">{{ ratingScore(state.rating) }}</span>
            </template>
            <span v-else class="text-ink-muted">{{ trans('media.not_rated') }}</span>
        </template>

        <div v-if="state" class="px-5 pb-4">
            <div class="flex touch-none" @mouseleave="hovered = null" @pointerdown="slide" @pointermove="slide">
                <button
                    v-for="star in RATING_MAX"
                    :key="star"
                    type="button"
                    :aria-label="ratingScore(star)"
                    :aria-pressed="(chosen ?? state.rating) === star"
                    class="pointer-fine:hover:bg-surface-overlay flex h-9.5 flex-1 cursor-pointer items-center justify-center rounded-lg transition-colors duration-100"
                    @pointerenter="$event.pointerType === 'mouse' && (hovered = star)"
                    @focus="($event.target as HTMLElement).matches(':focus-visible') && (hovered = star)"
                    @click="press(star, $event)"
                >
                    <Star
                        class="size-6.5 transition-colors duration-100 md:size-7"
                        :class="star <= shown ? 'fill-action-rating text-action-rating' : 'text-rating-empty'"
                        :stroke-width="1.6"
                    />
                </button>
            </div>
            <!-- The ends of the scale, and between them the score under the pointer: one star of ten does not say whether it is a 6 or a 7. -->
            <div class="text-ink-muted mt-1.5 grid grid-cols-[1fr_auto_1fr] px-2.5 text-sm" aria-hidden="true">
                <span>{{ RATING_MIN }}</span>
                <span class="text-action-rating font-semibold tabular-nums">{{ previewed === null ? '' : ratingScore(previewed) }}</span>
                <span class="text-right">{{ RATING_MAX }}</span>
            </div>

            <button
                v-if="touchScreen || chosen !== null"
                type="button"
                :disabled="!changed"
                class="bg-action-rating text-surface-base mt-4 flex h-12 w-full cursor-pointer items-center justify-center rounded-[10px] text-base font-semibold transition-opacity duration-150 disabled:cursor-default disabled:opacity-40"
                @click="save"
            >
                {{ chosen !== null ? trans('media.save_rating_of', { score: ratingScore(chosen) }) : trans('media.save_rating') }}
            </button>
        </div>

        <template v-if="state && state.rating !== null">
            <div class="border-line border-t" />
            <div class="py-2" :style="rowTint('remove')">
                <MenuRow
                    inset="dialog"
                    :icon="Trash2"
                    :label="trans('common.remove_rating')"
                    :description="trans('media.back_to_not_rated')"
                    @click="remove"
                />
            </div>
        </template>
    </AppModal>
</template>
