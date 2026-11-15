<script setup lang="ts">
import CollectionBanner from '@/components/media/CollectionBanner.vue';
import MediaHero from '@/components/media/MediaHero.vue';
import MediaStatusPanel from '@/components/media/MediaStatusPanel.vue';
import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import RecentlyViewed from '@/components/media/RecentlyViewed.vue';
import RecommendationsBand from '@/components/media/RecommendationsBand.vue';
import StickyActionsPanel from '@/components/media/StickyActionsPanel.vue';
import TitlePageLayout from '@/components/media/TitlePageLayout.vue';
import { useDeferredUserStates } from '@/composables/useMediaActions';
import { bookKindLabel } from '@/lib/badges';
import { bookContributors } from '@/lib/credits';
import { formatCalendarDate, formatCountOrNull } from '@/lib/format';
import type { BookExtra } from '@/types/extras';
import type { CollectionBannerData, DetailRow, MediaDetails, MediaSummary, UserStatesMap } from '@/types/media';
import { Head } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = defineProps<{
    book: MediaDetails<BookExtra>;
    recentlyViewed?: MediaSummary[];
    fromLibrary?: boolean;
    removedAtSource?: boolean;
    /** The series the book has a numbered place in. */
    collection?: CollectionBannerData | null;
    /** Deferred: the books Hardcover finds most like this one. */
    similar?: MediaSummary[];
    /** Deferred with the similar books: the viewer's state for their cards. */
    deferredUserStates?: UserStatesMap;
}>();

useDeferredUserStates(() => props.deferredUserStates);

const authors = computed(() => props.book.extra.authors ?? []);

// Clickable authors (linking to the Hardcover author page) below the overview,
// then the translator, illustrator and the rest in their own parts; the
// title-header chips keep the plain names for the at-a-glance byline.
const people = computed(() => [
    ...(props.book.extra.authorLinks ?? []).map((link) => ({ ...link, roles: [trans('media.author')] })),
    ...bookContributors(props.book.crew),
]);

const kind = computed(() => (props.book.extra.bookKind ? bookKindLabel(props.book.extra.bookKind) : null));

const literaryType = computed(() => {
    const nonfiction = props.book.extra.nonfiction;

    return nonfiction === true ? trans('media.nonfiction') : nonfiction === false ? trans('media.fiction') : null;
});

const pages = computed(() => props.book.extra.pages ?? null);

// Featured series membership, e.g. "Middle Earth #1". Positions can be
// fractional (novellas: #2.5) — trim the trailing ".0" of whole numbers.
const series = computed(() => {
    const series = props.book.extra.series;

    if (!series) {
        return null;
    }

    return series.position === null ? series.name : `${series.name} #${String(series.position).replace(/\.0$/, '')}`;
});

// Authors are not a chip: they show as the linkable byline under the
// overview (CreditHighlights) and in the details modal.
const chips = computed(() =>
    [kind.value, series.value, pages.value !== null ? transChoice('common.count_page', pages.value, { count: String(pages.value) }) : null].filter(
        (c): c is string => c !== null,
    ),
);

// The page shows the title and cover of the reader's own edition, so it says whose edition that is.
const localEdition = computed(() => {
    const edition = props.book.extra.localEdition;

    return edition
        ? [edition.publisher, edition.year === null ? null : String(edition.year)].filter((part) => part !== null).join(', ') || null
        : null;
});

const detailRows = computed<DetailRow[]>(() => [
    { label: trans('media.original_title'), value: props.book.extra.originalTitle ?? null },
    { label: trans('media.authors'), value: authors.value.join(', ') || null },
    { label: trans('media.series'), value: series.value },
    { label: trans('common.release_date'), value: formatCalendarDate(props.book.releaseDate) },
    { label: trans('media.local_edition'), value: localEdition.value },
    { label: trans('media.pages'), value: pages.value !== null ? String(pages.value) : null },
    { label: trans('media.book_kind'), value: kind.value },
    { label: trans('media.literary_type'), value: literaryType.value },
    { label: trans('media.isbn'), value: props.book.extra.isbn ?? null },
    { label: trans('media.readers'), value: formatCountOrNull(props.book.extra.readersCount) },
    { label: trans('common.ratings'), value: formatCountOrNull(props.book.extra.ratingsCount) },
    { label: trans('media.content_warnings'), value: (props.book.extra.contentWarnings ?? []).join(', ') || null },
]);
</script>

<template>
    <Head :title="book.title" />

    <TitlePageLayout :from-library="fromLibrary" :removed-at-source="removedAtSource">
        <template #hero>
            <!-- Books have no backdrops — the hero takes the cover's average
                 color (computed by Hardcover) instead. -->
            <MediaHero :backdrop-path="book.backdropPath" :color="book.extra.coverColor ?? null" :videos="book.videos" />
        </template>

        <template #header>
            <MediaTitleHeader :details="book" :chips="chips" :detail-rows="detailRows" />
        </template>

        <template #panels>
            <MediaStatusPanel :media="book" />
            <StickyActionsPanel :media="book" />
        </template>

        <OverviewSection :overview="book.overview" :people="people" />

        <template #below>
            <CollectionBanner v-if="collection" :collection="collection" />
        </template>

        <template #after>
            <!-- Asked of the provider after the page is drawn; about ten, rounded up to a whole row. -->
            <RecommendationsBand :similar="similar ?? []" :similar-title="trans('media.similar_books')" :shelf-size="10" />
        </template>

        <template #recent>
            <RecentlyViewed
                :title="book.title"
                :type="book.type"
                :backdrop-path="book.backdropPath"
                :color="book.extra.coverColor ?? null"
                :items="recentlyViewed ?? []"
            />
        </template>
    </TitlePageLayout>
</template>
