<script setup lang="ts">
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import SortControl from '@/components/SortControl.vue';
import { useShelfLimit } from '@/composables/useShelfLimit';
import AppLayout from '@/layouts/AppLayout.vue';
import { withCount } from '@/lib/format';
import { byRating, byTitle } from '@/lib/titleSort';
import { SELECT_CLASS } from '@/lib/ui';
import type { FranchiseDetails } from '@/types/generated';
import type { MediaSummary } from '@/types/media';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

// Everything under a franchise's name, one shelf at a time as a company's
// page shows its titles: the games of its series, then the rest beside them
// (spin-offs, side games). Read from the provider; nothing to follow here.
const props = defineProps<{ provider: string; franchise: FranchiseDetails }>();

type Shelf = 'series' | 'others';

const shelves = computed(() =>
    (
        [
            { key: 'series', label: trans('media.franchise_series'), items: props.franchise.series },
            { key: 'others', label: trans('media.franchise_others'), items: props.franchise.others },
        ] as { key: Shelf; label: string; items: MediaSummary[] }[]
    ).filter((shelf) => shelf.items.length > 0),
);

const selected = ref<Shelf | null>(null);
const current = computed(() => shelves.value.find((shelf) => shelf.key === selected.value) ?? shelves.value[0] ?? null);

// Release order first, as the provider's line reads; rating and title on request.
type Sort = 'release' | 'rating' | 'title';

const sort = ref<Sort>('release');
const dir = ref<'asc' | 'desc'>('asc');

watch(sort, () => (dir.value = sort.value === 'rating' ? 'desc' : 'asc'));

const sortOptions = computed(() => [
    { value: 'release', label: trans('common.release_date') },
    { value: 'rating', label: trans('common.community_rating') },
    { value: 'title', label: trans('common.title') },
]);

const sorted = computed(() => {
    const items = [...(current.value?.items ?? [])];
    const sign = dir.value === 'asc' ? 1 : -1;

    switch (sort.value) {
        case 'rating':
            return items.sort((a, b) => sign * byRating(a, b));
        case 'title':
            return items.sort((a, b) => sign * byTitle(a, b));
        default:
            // The provider's order already puts the announced last; a reversed
            // run keeps them last too, the newest released first.
            return dir.value === 'asc'
                ? items
                : [...items.filter((m) => m.releaseDate !== null).reverse(), ...items.filter((m) => m.releaseDate === null)];
    }
});

const LIMIT = useShelfLimit(18);
const expanded = ref(false);

watch([current, sort, dir], () => (expanded.value = false));

const visible = computed(() => (expanded.value ? sorted.value : sorted.value.slice(0, LIMIT.value)));
</script>

<template>
    <Head :title="franchise.name" />

    <AppLayout>
        <div class="max-w-content mx-auto w-full flex-1 space-y-8 px-4 pt-8 pb-20 md:px-10">
            <div>
                <div class="text-brand-soft text-sm font-bold">{{ trans('media.franchise') }}</div>
                <h1 class="text-hero m-0 leading-tight font-semibold">{{ franchise.name }}</h1>
            </div>

            <section v-if="current">
                <SectionHeading :title="current.label">
                    <div class="flex flex-wrap items-center gap-3">
                        <select
                            v-if="shelves.length > 1"
                            :aria-label="trans('media.pick_shelf')"
                            :value="current.key"
                            :class="SELECT_CLASS"
                            @change="selected = ($event.target as HTMLSelectElement).value as Shelf"
                        >
                            <option v-for="shelf in shelves" :key="shelf.key" :value="shelf.key">
                                {{ withCount(shelf.label, shelf.items.length) }}
                            </option>
                        </select>
                        <SortControl v-model:sort="sort" v-model:dir="dir" :options="sortOptions" />
                    </div>
                </SectionHeading>

                <MediaGrid>
                    <MediaCard v-for="media in visible" :key="media.ref" :media="media" />
                </MediaGrid>
                <ShowMoreToggle v-if="sorted.length > LIMIT" v-model="expanded" :hidden-count="sorted.length - LIMIT" />
            </section>
        </div>
    </AppLayout>
</template>
