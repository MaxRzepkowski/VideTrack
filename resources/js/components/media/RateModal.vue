<script setup lang="ts">
import MenuRow from '@/components/MenuRow.vue';
import AppModal from '@/components/modal/AppModal.vue';
import { shownState, stateKey, useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { RATING_MAX, RATING_MIN } from '@/lib/ratings';
import { rowTint } from '@/lib/ui';
import { trans } from 'laravel-vue-i18n';
import { Star, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const { modals, close } = useMediaModals();
const { rate } = useMediaActions();

const media = computed(() => modals.rate);
const state = computed(() => (media.value ? shownState(stateKey(media.value)) : null));

// Ten stars on every screen; one tap rates and closes. A pointer previews the
// score it rests on, so the row reads what a click would give.
const hovered = ref<number | null>(null);
const shown = computed(() => hovered.value ?? state.value?.rating ?? 0);

watch(media, () => {
    hovered.value = null;
});

function pick(score: number): void {
    if (media.value) {
        rate(media.value, score);
        close();
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
                <span class="bg-line-strong h-3 w-px shrink-0" aria-hidden="true" />
                <span class="text-action-rating font-medium">{{ state.rating }}/{{ RATING_MAX }}</span>
            </template>
            <span v-else class="text-ink-muted">{{ trans('media.not_rated') }}</span>
        </template>

        <div v-if="state" class="px-5 pb-4">
            <div class="flex" @mouseleave="hovered = null">
                <button
                    v-for="star in RATING_MAX"
                    :key="star"
                    type="button"
                    :aria-label="`${star}/${RATING_MAX}`"
                    :aria-pressed="state.rating === star"
                    class="hover:bg-surface-overlay flex h-9.5 flex-1 cursor-pointer items-center justify-center rounded-lg transition-colors duration-100"
                    @mouseenter="hovered = star"
                    @focus="hovered = star"
                    @click="pick(star)"
                >
                    <Star
                        class="size-6.5 transition-colors duration-100 md:size-7"
                        :class="star <= shown ? 'fill-action-rating text-action-rating' : 'text-rating-empty'"
                        :stroke-width="1.6"
                    />
                </button>
            </div>
            <div class="text-ink-muted mt-1.5 flex justify-between px-2.5 text-sm" aria-hidden="true">
                <span>{{ RATING_MIN }}</span>
                <span>{{ RATING_MAX }}</span>
            </div>
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
