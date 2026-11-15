<script setup lang="ts">
import { formatCalendarDate, formatDateTime, localDateTimeInput } from '@/lib/format';
import type { WhenMode } from '@/lib/watchedModal';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

// When the play happened. A line, not three rows: it reads as a fact you can
// correct, and unfolds into the picker on click.
const props = defineProps<{
    releaseDate: string | null;
    /** A playable without a release date cannot be dated to it. */
    releaseUnavailable: boolean;
    accent: string;
}>();

const mode = defineModel<WhenMode>('mode', { required: true });
const date = defineModel<string>('date', { required: true });

const open = ref(false);

const text = computed(() => {
    if (mode.value === 'release') {
        return formatCalendarDate(props.releaseDate) ?? trans('common.release_date');
    }

    if (mode.value === 'custom') {
        const parsed = new Date(date.value);

        return Number.isNaN(parsed.getTime()) ? trans('media.specific_date') : (formatDateTime(parsed.toISOString()) ?? trans('media.specific_date'));
    }

    return formatDateTime(new Date().toISOString()) ?? trans('media.now');
});

const options = computed(() => [
    { value: 'now' as const, label: trans('media.now'), disabled: false },
    { value: 'custom' as const, label: trans('media.specific_date'), disabled: false },
    { value: 'release' as const, label: trans('common.release_date'), disabled: props.releaseUnavailable },
]);
</script>

<template>
    <div class="flex flex-col">
        <button
            v-if="!open"
            v-tooltip="trans('media.change_the_date')"
            type="button"
            class="text-ink-muted hover:text-ink mx-auto mt-2.5 text-sm font-medium underline decoration-dotted underline-offset-4 transition-colors duration-150"
            @click="open = true"
        >
            {{ text }}
        </button>

        <div v-else class="mt-3 flex flex-col gap-2">
            <div class="bg-surface-raised flex gap-1 rounded-xl p-1">
                <button
                    v-for="option in options"
                    :key="option.value"
                    type="button"
                    :disabled="option.disabled"
                    :aria-pressed="mode === option.value"
                    class="flex-1 rounded-lg px-2 py-2 text-sm font-semibold transition-colors duration-150 disabled:opacity-35"
                    :class="mode === option.value ? 'bg-surface-overlay text-ink' : 'text-ink-muted hover:text-ink-secondary'"
                    @click="mode = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
            <input
                v-if="mode === 'custom'"
                v-model="date"
                type="datetime-local"
                :max="localDateTimeInput()"
                :aria-label="trans('media.specific_date')"
                class="border-line bg-surface-raised text-ink w-full rounded-xl border px-3 py-2.5 text-base font-semibold [color-scheme:dark] focus:outline-none"
                :style="{ borderColor: accent }"
            />
        </div>
    </div>
</template>
