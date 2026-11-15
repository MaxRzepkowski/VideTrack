<script setup lang="ts">
import SectionHeading from '@/components/SectionHeading.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import { languageRows } from '@/lib/gamePlay';
import type { LanguageSupport } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';
import { Check } from '@lucide/vue';
import { computed, ref } from 'vue';

// The languages a game speaks and how, as a store page tables them: the
// reader's own first, a handful open, the rest behind the toggle.
const props = defineProps<{ languages: LanguageSupport[] }>();

const SHOWN = 5;
const rows = computed(() => languageRows(props.languages));
const expanded = ref(false);
const visible = computed(() => (expanded.value ? rows.value : rows.value.slice(0, SHOWN)));

const columns = computed(() => [
    { key: 'interface' as const, label: trans('media.languages_interface') },
    { key: 'audio' as const, label: trans('media.languages_audio') },
    { key: 'subtitles' as const, label: trans('media.languages_subtitles') },
]);
</script>

<template>
    <section v-if="rows.length">
        <SectionHeading :title="trans('media.languages')" />

        <div class="border-line-subtle bg-surface rounded-2xl border px-5 py-3">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-ink-muted font-bold">
                        <th scope="col" class="py-2 text-left">
                            <span class="sr-only">{{ trans('media.languages') }}</span>
                        </th>
                        <th v-for="column in columns" :key="column.key" scope="col" class="w-24 py-2 text-center sm:w-32">{{ column.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in visible" :key="row.locale" class="border-line-subtle border-t">
                        <th scope="row" class="text-ink-secondary py-2 text-left text-base font-semibold">{{ row.name }}</th>
                        <td v-for="column in columns" :key="column.key" class="py-2 text-center">
                            <template v-if="row[column.key]">
                                <Check class="text-media-game mx-auto size-4.5" :stroke-width="2.5" aria-hidden="true" />
                                <span class="sr-only">{{ trans('media.yes') }}</span>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ShowMoreToggle v-if="rows.length > SHOWN" v-model="expanded" :hidden-count="rows.length - SHOWN" />
    </section>
</template>
