<script setup lang="ts">
import { Dialog, DialogContent, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import type { AgeRatingImage } from '@/lib/ageRatings';
import type { CertificationRow } from '@/types/media';
import { trans } from 'laravel-vue-i18n';

// Every classification of a title in one dialog behind its chip: a film's by
// country, a game's by board; the caller words the rows.
withDefaults(
    defineProps<{
        label: string;
        meaning: string | null;
        rows: CertificationRow[];
        /** The board's mark for the chip itself, where one is kept (a game's PEGI 18). */
        image?: AgeRatingImage | null;
    }>(),
    { image: null },
);
</script>

<template>
    <Dialog>
        <DialogTrigger as-child>
            <button
                v-if="image"
                v-tooltip="meaning ?? label"
                type="button"
                class="hover:ring-brand rounded-sm transition-shadow duration-150 hover:ring-2"
            >
                <img :src="image.src" :alt="label" :width="image.width" :height="image.height" loading="lazy" class="h-8 w-auto" />
            </button>
            <button
                v-else
                v-tooltip="meaning"
                type="button"
                class="border-line-strong hover:border-brand hover:text-ink bg-scrim/30 rounded-md border px-2.5 py-1 transition-colors duration-150"
            >
                {{ label }}
            </button>
        </DialogTrigger>

        <DialogContent class="border-line-subtle bg-surface max-w-panel flex max-h-[85dvh] flex-col rounded-2xl p-4 sm:p-7">
            <DialogTitle class="shrink-0 pr-10 text-2xl font-semibold">
                {{ rows.length > 1 ? trans('media.certifications_worldwide') : trans('common.certification') }}
            </DialogTitle>

            <!-- Every row alike: the mark in a fixed column so the names line
                 up, each reason the same tag whether or not the board draws an
                 icon for it. Two newspaper columns rather than a grid, so a
                 short row sits under the one above it instead of beside a tall
                 one with a gap below. -->
            <div class="overflow-y-auto">
                <ul class="m-0 list-none gap-x-8 p-0 sm:columns-2">
                    <li v-for="row in rows" :key="row.key" class="border-line-subtle flex break-inside-avoid items-start gap-4 border-b py-4">
                        <span class="flex w-14 shrink-0 justify-center">
                            <img
                                v-if="row.image"
                                :src="row.image.src"
                                :alt="`${row.name} ${row.code}`"
                                :width="row.image.width"
                                :height="row.image.height"
                                loading="lazy"
                                class="h-14 w-auto max-w-14 object-contain"
                            />
                            <span
                                v-else
                                class="border-line-strong text-ink-secondary flex min-h-14 w-14 items-center justify-center rounded-lg border px-1 text-center text-sm font-bold"
                            >
                                {{ row.code }}
                            </span>
                        </span>

                        <span class="flex min-w-0 flex-1 flex-col gap-2 text-sm">
                            <span class="text-ink text-base font-semibold">{{ row.name }}</span>
                            <span v-if="row.meaning" class="text-ink-secondary leading-relaxed">{{ row.meaning }}</span>
                            <ul
                                v-if="row.descriptors.length"
                                :aria-label="trans('media.rating_descriptors')"
                                class="m-0 flex list-none flex-wrap gap-2 p-0"
                            >
                                <li
                                    v-for="descriptor in row.descriptors"
                                    :key="descriptor.text"
                                    class="bg-surface-raised text-ink-secondary inline-flex items-center gap-1.5 rounded-lg py-1 pr-2.5 font-semibold"
                                    :class="descriptor.image ? 'pl-1' : 'pl-2.5'"
                                >
                                    <img
                                        v-if="descriptor.image"
                                        :src="descriptor.image.src"
                                        alt=""
                                        :width="descriptor.image.width"
                                        :height="descriptor.image.height"
                                        loading="lazy"
                                        class="size-6 object-contain"
                                    />
                                    {{ descriptor.text }}
                                </li>
                            </ul>
                            <span v-if="row.synopsis" class="text-ink-muted leading-relaxed">{{ row.synopsis }}</span>
                        </span>
                    </li>
                </ul>
            </div>
        </DialogContent>
    </Dialog>
</template>
