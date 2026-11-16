<script setup lang="ts">
import ClampedText from '@/components/ClampedText.vue';
import FactTilesSection from '@/components/media/FactTilesSection.vue';
import MediaHero from '@/components/media/MediaHero.vue';
import MediaStatusPanel from '@/components/media/MediaStatusPanel.vue';
import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import RecentlyViewed from '@/components/media/RecentlyViewed.vue';
import RecommendationsBand from '@/components/media/RecommendationsBand.vue';
import RelatedTitles from '@/components/media/RelatedTitles.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import StickyActionsPanel from '@/components/media/StickyActionsPanel.vue';
import TitlePageLayout from '@/components/media/TitlePageLayout.vue';
import { mangaAuthorCredits } from '@/lib/creators';
import { rankingTiles } from '@/lib/factTiles';
import { alternativeTitlesLine, formatCountOrNull, formatReleaseDate, formatYearSpan } from '@/lib/format';
import { mangaFormatLabel } from '@/lib/mangaFormats';
import { titleStatusLabel } from '@/lib/titleStatus';
import type { MangaExtra } from '@/types/extras';
import type { DetailRow, MediaDetails, MediaSummary, TitleTie } from '@/types/media';
import { Head } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    manga: MediaDetails<MangaExtra>;
    recentlyViewed?: MediaSummary[];
    relatedTitles?: TitleTie[];
    fromLibrary?: boolean;
    removedAtSource?: boolean;
}>();

const authors = computed(() => props.manga.extra.authors ?? []);

// Authors as the byline under the overview, each under what they did, named without a page to link to.
const authorPeople = computed(() => mangaAuthorCredits(authors.value, props.manga.extra.authorRoles ?? {}));

// Where it stands in MAL's rankings, as tiles beside the overview: what a reader weighs before starting one.
const rankings = computed(() => rankingTiles(props.manga.rankings));

const chapters = computed(() => props.manga.extra.chapters ?? null);

const volumes = computed(() => props.manga.extra.volumes ?? null);

// Manga / Manhwa / Manhua / One-shot — MAL mixes formats in one catalogue.
const format = computed(() => mangaFormatLabel(props.manga.extra.mangaFormat));

// Authors are the byline under the overview, not a chip.
const chips = computed(() =>
    [format.value, chapters.value !== null ? transChoice('common.count_chapter', chapters.value, { count: String(chapters.value) }) : null].filter(
        (c): c is string => c !== null,
    ),
);

const detailRows = computed<DetailRow[]>(() => [
    { label: trans('media.authors'), value: authors.value.join(', ') || null },
    { label: trans('media.original_title'), value: props.manga.extra.originalTitle ?? null },
    { label: trans('media.format'), value: format.value },
    { label: trans('media.magazine'), value: props.manga.extra.magazine ?? null },
    { label: trans('common.release_date'), value: formatReleaseDate(props.manga.releaseDate, props.manga.extra.releasePrecision) },
    {
        label: trans('media.publication_years'),
        // A manga still to come has no years yet: its date is the release date above.
        value:
            props.manga.status === 'not_yet_published'
                ? null
                : formatYearSpan(props.manga.releaseDate, props.manga.extra.endDate ?? null, props.manga.extra.stillPublishing ?? false),
    },
    { label: trans('common.status'), value: titleStatusLabel(props.manga.status) },
    { label: trans('media.chapters'), value: chapters.value !== null ? String(chapters.value) : null },
    { label: trans('media.volumes'), value: volumes.value !== null ? String(volumes.value) : null },
    { label: trans('media.readers'), value: formatCountOrNull(props.manga.extra.readersCount) },
    { label: trans('common.ratings'), value: formatCountOrNull(props.manga.extra.ratingsCount) },
    // Trivia, so it sits below the facts people came for, as on a film's page.
    { label: trans('media.alternative_titles'), value: alternativeTitlesLine(props.manga.extra.alternativeTitles) },
]);
</script>

<template>
    <Head :title="manga.title" />

    <TitlePageLayout :from-library="fromLibrary" :removed-at-source="removedAtSource" content-class="pb-20">
        <template #hero>
            <!-- Manga have no backdrops and MAL serves no cover color — a deep
                 tint of the manga type color keeps the hero from going flat. -->
            <MediaHero :backdrop-path="manga.backdropPath" color="var(--color-media-manga-shade)" :videos="manga.videos" />
        </template>

        <template #header>
            <MediaTitleHeader :details="manga" :chips="chips" :detail-rows="detailRows" />
        </template>

        <template #panels>
            <MediaStatusPanel :media="manga" />
            <StickyActionsPanel :media="manga" />
        </template>

        <OverviewSection :overview="manga.overview" :people="authorPeople" />

        <FactTilesSection :title="trans('media.rankings')" :tiles="rankings" type="manga" />

        <!-- MAL's notes (awards, sales, editions abroad) beside the synopsis, in a card as the page's other facts are. -->
        <section v-if="manga.extra.background">
            <SectionHeading :title="trans('media.background')" />
            <div class="border-line-subtle bg-surface rounded-2xl border px-5 py-4">
                <ClampedText :text="manga.extra.background" :lines="4" />
            </div>
        </section>

        <template #below>
            <RelatedTitles :titles="relatedTitles ?? []" :source="null" />
        </template>

        <template #after>
            <RecommendationsBand :related="manga.related" :related-title="trans('media.recommended_manga')" />
        </template>

        <template #recent>
            <RecentlyViewed
                :title="manga.title"
                :type="manga.type"
                :backdrop-path="manga.backdropPath"
                color="var(--color-media-manga-shade)"
                :items="recentlyViewed ?? []"
            />
        </template>
    </TitlePageLayout>
</template>
