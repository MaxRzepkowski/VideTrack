<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import { useShelfLimit } from '@/composables/useShelfLimit';
import AppLayout from '@/layouts/AppLayout.vue';
import { SELECT_CLASS } from '@/lib/ui';
import type { PlatformDetails } from '@/types/generated';
import type { MediaSummary } from '@/types/media';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Gamepad2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

// A platform's page: what machine it is, then its games one shelf at a time —
// those coming (soonest first) and the best rated. Read from the provider;
// nothing to follow here (a platform gets hundreds of games a year).
const props = defineProps<{ provider: string; platform: PlatformDetails }>();

type Shelf = 'upcoming' | 'topRated';

const shelves = computed(() =>
    (
        [
            { key: 'upcoming', label: trans('media.platform_upcoming'), items: props.platform.upcoming },
            { key: 'topRated', label: trans('media.platform_top_rated'), items: props.platform.topRated },
        ] as { key: Shelf; label: string; items: MediaSummary[] }[]
    ).filter((shelf) => shelf.items.length > 0),
);

const selected = ref<Shelf | null>(null);
const current = computed(() => shelves.value.find((shelf) => shelf.key === selected.value) ?? shelves.value[0] ?? null);

const facts = computed(() =>
    [
        { label: trans('media.platform_kind'), value: props.platform.kind },
        { label: trans('media.platform_family'), value: props.platform.family },
        { label: trans('media.platform_generation'), value: props.platform.generation === null ? null : String(props.platform.generation) },
    ].filter((fact) => fact.value !== null),
);

const LIMIT = useShelfLimit(18);
const expanded = ref(false);

watch(current, () => (expanded.value = false));

const visible = computed(() => (expanded.value ? (current.value?.items ?? []) : (current.value?.items ?? []).slice(0, LIMIT.value)));
</script>

<template>
    <Head :title="platform.name" />

    <AppLayout>
        <div class="max-w-content mx-auto w-full flex-1 space-y-8 px-4 pt-8 pb-20 md:px-10">
            <div class="flex flex-col items-start gap-8 md:flex-row">
                <!-- Provider logos are drawn for a light background. -->
                <div
                    class="border-line-subtle shadow-scrim/40 flex h-40 w-55 shrink-0 items-center justify-center overflow-hidden rounded-2xl border p-6 shadow-2xl"
                    :class="platform.logoPath ? 'bg-paper' : 'bg-surface'"
                >
                    <img
                        v-if="platform.logoPath"
                        :src="platform.logoPath"
                        :alt="platform.name"
                        width="284"
                        height="160"
                        loading="lazy"
                        class="max-h-full max-w-full object-contain"
                    />
                    <Gamepad2 v-else class="text-ink-muted size-16" />
                </div>

                <div class="min-w-0 flex-1 pt-2">
                    <div class="text-brand-soft text-sm font-bold">{{ trans('media.platform') }}</div>
                    <h1 class="text-hero m-0 mb-4 leading-tight font-semibold">{{ platform.name }}</h1>

                    <div v-if="facts.length" class="mb-6 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-3">
                        <div v-for="fact in facts" :key="fact.label">
                            <div class="text-ink-muted mb-1 text-sm font-bold">{{ fact.label }}</div>
                            <div class="text-ink text-base font-semibold">{{ fact.value }}</div>
                        </div>
                    </div>

                    <p v-if="platform.summary" class="text-ink-secondary max-w-reading-lg m-0 text-base leading-relaxed">{{ platform.summary }}</p>
                </div>
            </div>

            <section v-if="current">
                <SectionHeading :title="current.label">
                    <select
                        v-if="shelves.length > 1"
                        :aria-label="trans('media.pick_shelf')"
                        :value="current.key"
                        :class="SELECT_CLASS"
                        @change="selected = ($event.target as HTMLSelectElement).value as Shelf"
                    >
                        <option v-for="shelf in shelves" :key="shelf.key" :value="shelf.key">{{ shelf.label }}</option>
                    </select>
                </SectionHeading>

                <MediaGrid>
                    <MediaCard v-for="media in visible" :key="media.ref" :media="media" />
                </MediaGrid>
                <ShowMoreToggle v-if="current.items.length > LIMIT" v-model="expanded" :hidden-count="current.items.length - LIMIT" />
            </section>
        </div>
    </AppLayout>
</template>
