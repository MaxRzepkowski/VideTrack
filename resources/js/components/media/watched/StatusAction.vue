<script setup lang="ts">
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { dateTimeInputInstant, formatShortDay, formatShortReleaseDate, localDateTimeInput } from '@/lib/format';
import { TONE_LOOK, type ActionTone, type WhenMode } from '@/lib/watchedModal';
import type { DatePrecision } from '@/types/generated';
import type { LucideIcon } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { ChevronDown } from '@lucide/vue';
import { computed, useId } from 'vue';

/**
 * The dialog's one action, in the colour of its kind of write. A write that
 * happens on a day (a play, a drop) splits the button: its right side picks
 * when — now, the release date, or a moment of your own, which opens a field
 * under the button.
 */
const props = defineProps<{
    label: string;
    icon: LucideIcon;
    tone: ActionTone;
    /** The write is dated, so the button offers when. */
    dated: boolean;
    releaseDate: string | null;
    /** How far the release date is known: a year alone reads as the year. */
    releasePrecision?: DatePrecision;
    /** A playable without a release date cannot be dated to it. */
    releaseUnavailable: boolean;
}>();

const mode = defineModel<WhenMode>('mode', { required: true });
const date = defineModel<string>('date', { required: true });

defineEmits<{ act: [] }>();

const fieldId = useId();

/** When, as short as the button's side has room for. */
const when = computed(() => {
    if (mode.value === 'release' && props.releaseDate !== null) {
        return formatShortReleaseDate(props.releaseDate, props.releasePrecision);
    }

    if (mode.value === 'custom') {
        // Read as the wall time of the account's zone, as the write reads it.
        const picked = dateTimeInputInstant(date.value);

        return picked === null ? trans('media.specific_date') : formatShortDay(picked);
    }

    return trans('media.now');
});

const options = computed(() => [
    { value: 'now' as const, label: trans('media.now'), disabled: false },
    { value: 'release' as const, label: trans('common.release_date'), disabled: props.releaseUnavailable },
    { value: 'custom' as const, label: trans('media.specific_date'), disabled: false },
]);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex h-12 overflow-hidden rounded-[10px]" :class="TONE_LOOK[tone].fill">
            <button
                type="button"
                class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 px-4 text-base font-semibold transition-opacity duration-150 hover:opacity-90"
                :class="dated ? '' : 'justify-center'"
                @click="$emit('act')"
            >
                <component :is="icon" class="size-5 shrink-0" :stroke-width="2.4" />
                <span class="truncate">{{ label }}</span>
            </button>
            <template v-if="dated">
                <span class="my-auto h-6 w-px shrink-0 bg-current opacity-60" aria-hidden="true" />
                <DropdownMenu>
                    <DropdownMenuTrigger
                        :aria-label="trans('media.change_the_date')"
                        class="flex shrink-0 cursor-pointer items-center gap-1 pr-3 pl-3.5 text-base font-semibold transition-opacity duration-150 hover:opacity-90"
                    >
                        {{ when }}
                        <ChevronDown class="size-4.5" :stroke-width="2.4" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="border-line-strong bg-surface text-ink w-52 rounded-xl p-1.5">
                        <DropdownMenuItem
                            v-for="option in options"
                            :key="option.value"
                            :disabled="option.disabled"
                            class="focus:bg-surface-overlay rounded-lg px-3 py-2 text-base"
                            :class="mode === option.value ? 'text-ink font-semibold' : 'text-ink-secondary'"
                            @select="mode = option.value"
                        >
                            {{ option.label }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </div>

        <!-- A moment of your own: the field the right side opened. -->
        <label
            v-if="dated && mode === 'custom'"
            :for="fieldId"
            class="border-brand-soft bg-surface-raised flex h-13 items-center gap-2 rounded-[10px] border-2 pr-3.5 pl-4"
        >
            <!-- On a phone the field alone fills the line; the label stays for screen readers. -->
            <span class="text-ink-muted min-w-0 flex-1 text-[15px] max-sm:sr-only">{{ trans('media.date_and_time') }}</span>
            <input
                :id="fieldId"
                v-model="date"
                type="datetime-local"
                :max="localDateTimeInput()"
                class="text-ink min-w-0 bg-transparent text-right text-lg font-semibold [color-scheme:dark] focus:outline-none max-sm:flex-1 max-sm:text-left"
            />
        </label>
    </div>
</template>
