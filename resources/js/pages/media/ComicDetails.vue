<script setup lang="ts">
import MediaHero from '@/components/media/MediaHero.vue';
import MediaStatusPanel from '@/components/media/MediaStatusPanel.vue';
import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import RecentlyViewed from '@/components/media/RecentlyViewed.vue';
import RelatedTitles from '@/components/media/RelatedTitles.vue';
import StickyActionsPanel from '@/components/media/StickyActionsPanel.vue';
import TitlePageLayout from '@/components/media/TitlePageLayout.vue';
import { plainCredits } from '@/lib/creators';
import type { ComicExtra } from '@/types/extras';
import type { DetailRow, MediaDetails, MediaSummary, TitleTie } from '@/types/media';
import { Head } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    comic: MediaDetails<ComicExtra>;
    recentlyViewed?: MediaSummary[];
    fromLibrary?: boolean;
    removedAtSource?: boolean;
    otherMedia?: TitleTie[];
}>();

const creators = computed(() => props.comic.extra.creators ?? []);

// Creators as the byline under the overview, named without a page to link to.
const creatorPeople = computed(() => plainCredits(creators.value, trans('media.creator')));

const publisher = computed(() => props.comic.extra.publisher ?? null);

const issues = computed(() => props.comic.extra.issues ?? null);

// Creators are the byline under the overview, not a chip; the publisher is a chip.
const chips = computed(() =>
    [publisher.value, issues.value !== null ? transChoice('common.count_issue', issues.value, { count: String(issues.value) }) : null].filter(
        (c): c is string => c !== null,
    ),
);

const detailRows = computed<DetailRow[]>(() => [
    { label: trans('media.publisher'), value: publisher.value },
    { label: trans('media.creators'), value: creators.value.join(', ') || null },
    { label: trans('media.first_issue_year'), value: props.comic.year !== null ? String(props.comic.year) : null },
    { label: trans('media.issues'), value: issues.value !== null ? String(issues.value) : null },
]);
</script>

<template>
    <Head :title="comic.title" />

    <TitlePageLayout :from-library="fromLibrary" :removed-at-source="removedAtSource" content-class="pb-20">
        <template #hero>
            <!-- Comic volumes have no backdrops and ComicVine serves no cover
                 color — a deep tint of the comic type color keeps the hero from
                 going flat. -->
            <MediaHero :backdrop-path="comic.backdropPath" color="var(--color-media-comic-shade)" :videos="comic.videos" />
        </template>

        <template #header>
            <MediaTitleHeader :details="comic" :chips="chips" :detail-rows="detailRows" />
        </template>

        <template #panels>
            <MediaStatusPanel :media="comic" />
            <StickyActionsPanel :media="comic" />
        </template>

        <OverviewSection :overview="comic.overview" :people="creatorPeople" />

        <template #below>
            <RelatedTitles :titles="otherMedia ?? []" :heading="trans('media.in_other_media')" />
            <RecentlyViewed
                class="pt-16 md:pt-32"
                :title="comic.title"
                :type="comic.type"
                :backdrop-path="comic.backdropPath"
                color="var(--color-media-comic-shade)"
                :items="recentlyViewed ?? []"
            />
        </template>
    </TitlePageLayout>
</template>
