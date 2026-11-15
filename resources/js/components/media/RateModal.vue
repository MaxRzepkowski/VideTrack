<script setup lang="ts">
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { shownState, stateKey, useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { trans } from 'laravel-vue-i18n';
import { Minus, Plus, Star } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const { modals, close } = useMediaModals();
const { rate } = useMediaActions();

const media = computed(() => modals.rate);
const state = computed(() => (media.value ? shownState(stateKey(media.value)) : null));

// Desktop: a hover-highlighted row of ten stars; one click commits.
const hovered = ref<number | null>(null);
const highlighted = computed(() => hovered.value ?? state.value?.rating ?? 0);

// Mobile: a compact +/- stepper — ten stars overflow the narrow modal. The
// local score is edited with the stepper / level bar and committed on Save.
const value = ref(0);

watch(media, () => {
    hovered.value = null;
    value.value = state.value?.rating ?? 0;
});

const changed = computed(() => value.value !== (state.value?.rating ?? 0));

function step(delta: number): void {
    value.value = Math.min(10, Math.max(0, value.value + delta));
}

// Desktop star click: rate immediately and close.
function pick(picked: number): void {
    if (!media.value) {
        return;
    }

    rate(media.value, picked);
    close();
}

// Mobile Save: commit the stepper value (0 clears the rating).
function save(): void {
    if (!media.value) {
        return;
    }

    rate(media.value, value.value > 0 ? value.value : null);
    close();
}

function remove(): void {
    if (!media.value) {
        return;
    }

    rate(media.value, null);
    close();
}
</script>

<template>
    <Dialog :open="media !== null" @update:open="(open: boolean) => !open && close()">
        <DialogContent v-if="media && state" class="border-line-subtle bg-surface max-w-md rounded-2xl p-5 sm:p-7">
            <DialogTitle class="min-w-0 truncate text-2xl font-semibold">{{ media.title }}</DialogTitle>

            <!-- Desktop: ten stars (they fit the wider modal) -->
            <div class="hidden flex-col items-center gap-4 sm:flex">
                <div class="flex items-center gap-1" @mouseleave="hovered = null">
                    <button
                        v-for="star in 10"
                        :key="star"
                        type="button"
                        :aria-label="`${star}/10`"
                        :aria-pressed="state?.rating === star"
                        class="p-0.5"
                        @mouseenter="hovered = star"
                        @focus="hovered = star"
                        @click="pick(star)"
                    >
                        <Star
                            class="size-7 transition-colors duration-100"
                            :class="star <= highlighted ? 'fill-action-rating text-action-rating' : 'text-ink-faint'"
                            :stroke-width="1.4"
                        />
                    </button>
                </div>

                <div class="text-lg font-bold" :class="highlighted > 0 ? 'text-ink' : 'text-ink-faint'">
                    {{ highlighted > 0 ? `${highlighted}/10` : trans('common.n_a') }}
                </div>

                <button v-if="state.rating !== null" type="button" class="text-danger text-sm font-semibold" @click="remove">
                    {{ trans('common.remove_rating') }}
                </button>
            </div>

            <!-- Mobile: compact +/- stepper -->
            <div class="flex flex-col items-center gap-6 sm:hidden">
                <div class="flex w-full items-center justify-center gap-4">
                    <button
                        type="button"
                        :aria-label="trans('media.decrease_rating')"
                        :disabled="value <= 0"
                        class="border-line bg-surface-raised text-ink hover:border-line-strong flex size-12 shrink-0 items-center justify-center rounded-xl border transition-colors duration-150 disabled:opacity-40"
                        @click="step(-1)"
                    >
                        <Minus class="size-5" />
                    </button>

                    <div class="flex min-w-24 items-baseline justify-center gap-1">
                        <Star
                            class="fill-action-rating text-action-rating mr-1 size-6 self-center"
                            :class="value > 0 ? '' : 'opacity-30'"
                            :stroke-width="1.4"
                        />
                        <span class="text-5xl font-extrabold tabular-nums" :class="value > 0 ? 'text-ink' : 'text-ink-faint'">
                            {{ value > 0 ? value : trans('common.n_a') }}
                        </span>
                        <span class="text-ink-muted text-xl font-bold">/10</span>
                    </div>

                    <button
                        type="button"
                        :aria-label="trans('media.increase_rating')"
                        :disabled="value >= 10"
                        class="border-line bg-surface-raised text-ink hover:border-line-strong flex size-12 shrink-0 items-center justify-center rounded-xl border transition-colors duration-150 disabled:opacity-40"
                        @click="step(1)"
                    >
                        <Plus class="size-5" />
                    </button>
                </div>

                <!-- Level bar: ten segments filled up to the score; tapping one
                     jumps straight there (the quick-select the stars give). -->
                <div class="flex w-full gap-1">
                    <button
                        v-for="n in 10"
                        :key="n"
                        type="button"
                        :aria-label="`${n}/10`"
                        class="h-3 flex-1 rounded-full transition-colors duration-100"
                        :class="n <= value ? 'bg-action-rating' : 'bg-surface-overlay hover:bg-line-strong'"
                        @click="value = n"
                    ></button>
                </div>

                <div class="flex w-full items-center justify-between gap-3">
                    <button v-if="state.rating !== null" type="button" class="text-danger text-sm font-semibold" @click="remove">
                        {{ trans('common.remove_rating') }}
                    </button>
                    <span v-else></span>
                    <button
                        type="button"
                        :disabled="!changed"
                        class="bg-brand hover:bg-brand/90 text-on-accent rounded-xl px-6 py-2.5 text-base font-semibold transition-colors duration-150 disabled:opacity-40"
                        @click="save"
                    >
                        {{ trans('common.save') }}
                    </button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
