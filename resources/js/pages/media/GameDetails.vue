<script setup lang="ts">
import ClampedText from '@/components/ClampedText.vue';
import EventShelf from '@/components/events/EventShelf.vue';
import CollectionBanner from '@/components/media/CollectionBanner.vue';
import FactTilesSection from '@/components/media/FactTilesSection.vue';
import GameLanguagesSection from '@/components/media/game/GameLanguagesSection.vue';
import GameStoresSection from '@/components/media/game/GameStoresSection.vue';
import MediaHero from '@/components/media/MediaHero.vue';
import MediaStatusPanel from '@/components/media/MediaStatusPanel.vue';
import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import PhotosSection from '@/components/media/PhotosSection.vue';
import RecentlyViewed from '@/components/media/RecentlyViewed.vue';
import RecommendationsBand from '@/components/media/RecommendationsBand.vue';
import RelatedTitles from '@/components/media/RelatedTitles.vue';
import StickyActionsPanel from '@/components/media/StickyActionsPanel.vue';
import TitlePageLayout from '@/components/media/TitlePageLayout.vue';
import TitleShelf from '@/components/media/TitleShelf.vue';
import VideosSection from '@/components/media/VideosSection.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import { shownState, stateKey } from '@/composables/useMediaActions';
import { ageRatingImage, descriptorImage } from '@/lib/ageRatings';
import type { FactTile } from '@/lib/factTiles';
import { gameTypeBadge } from '@/lib/badges';
import { formatReleaseDate, formatRuntime } from '@/lib/format';
import { multiplayerLabel, providerRatingLabel } from '@/lib/gamePlay';
import { shownStatus, shownStatusLabel } from '@/lib/status';
import { titleStatusLabel } from '@/lib/titleStatus';
import type { TooltipRow } from '@/lib/tooltip';
import { mediaUrl } from '@/lib/urls';
import type { GameExtra } from '@/types/extras';
import type { EventEntry } from '@/types/generated';
import type { CertificationRow, Chip, CollectionBannerData, CreatorLink, DetailRow, MediaDetails, MediaSummary, TitleTie } from '@/types/media';
import { Head, Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Gauge, Timer, Trophy } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    game: MediaDetails<GameExtra>;
    recentlyViewed?: MediaSummary[];
    fromLibrary?: boolean;
    removedAtSource?: boolean;
    otherMedia?: TitleTie[];
    /** The series it belongs to, where IGDB curates one. */
    collection?: CollectionBannerData | null;
    /** The showcases that showed it, the latest first. */
    shownAt?: EventEntry[];
}>();

const list = (key: 'platforms' | 'gameModes' | 'engines' | 'franchises') => props.game.extra[key]?.join(', ') || null;

// Clickable studios (linking to the IGDB company page) below the overview,
// merged so a company that both developed and published shows both roles.
const companyPeople = computed(() => {
    const byKey = new Map<string, CreatorLink & { roles: string[] }>();

    const add = (key: 'developerLinks' | 'publisherLinks', role: string) => {
        for (const link of props.game.extra[key] ?? []) {
            const entry = byKey.get(link.ref ?? link.name) ?? { ...link, roles: [] };
            if (!entry.roles.includes(role)) {
                entry.roles.push(role);
            }
            byKey.set(link.ref ?? link.name, entry);
        }
    };

    add('developerLinks', trans('media.developer'));
    add('publisherLinks', trans('media.publisher'));

    return [...byKey.values()];
});

// A status other than released (Early Access, Alpha, Beta…) is part of the
// game's identity — surfaced as a title chip, not just a modal detail row.
const titleStatusChip = (status: MediaDetails['status']): string | null => (status !== 'released' ? titleStatusLabel(status) : null);

// The age rating opens every board's rating, with the content behind each.
const ageRatingRows = computed<CertificationRow[]>(() =>
    (props.game.extra.ageRatings ?? []).map((rating) => ({
        key: rating.board,
        name: rating.board,
        code: rating.rating,
        meaning: null,
        descriptors: rating.descriptors.map((text) => ({ text, image: descriptorImage(rating.board, text) })),
        synopsis: rating.synopsis,
        image: ageRatingImage(rating.board, rating.rating),
    })),
);

const chips = computed<Chip[]>(() => {
    const certification = props.game.certification;
    const rated: Chip | null =
        certification === null
            ? null
            : ageRatingRows.value.length > 0
              ? {
                    label: certification,
                    certification: true,
                    rows: ageRatingRows.value,
                    // The chip is the viewer's board's rating ("PEGI 18"): its row carries the mark.
                    image: ageRatingRows.value.find((row) => `${row.name} ${row.code}` === certification)?.image ?? null,
                }
              : certification;
    // What kind of release it is (a port, a remaster, a DLC), as its card's badge says.
    const facts: (Chip | null)[] = [gameTypeBadge(props.game)?.label ?? null, rated, titleStatusChip(props.game.status)];

    return facts.filter((chip): chip is Chip => chip !== null);
});

const detailRows = computed<DetailRow[]>(() => [
    { label: trans('common.status'), value: titleStatusLabel(props.game.status) },
    { label: trans('common.release_date'), value: formatReleaseDate(props.game.releaseDate, props.game.extra.releasePrecision) },
    // Developers / publishers ride the modal's linked "Companies" section
    // instead (clickable to their company pages), not as plain-text rows.
    {
        label: trans('media.platforms'),
        value: list('platforms'),
        links: (props.game.extra.platformLinks ?? []).map((platform) => ({
            name: platform.name,
            href: route('platforms.show', { provider: props.game.provider, ref: platform.ref }),
        })),
    },
    { label: trans('media.game_modes'), value: list('gameModes') },
    { label: trans('media.multiplayer'), value: (props.game.extra.multiplayer ?? []).map(multiplayerLabel).join(', ') || null },
    { label: trans('media.perspectives'), value: props.game.extra.perspectives?.join(', ') || null },
    {
        label: trans('media.franchises'),
        value: list('franchises'),
        links: (props.game.extra.franchiseLinks ?? []).map((franchise) => ({
            name: franchise.name,
            href: route('franchises.show', { provider: props.game.provider, ref: franchise.ref }),
        })),
    },
    { label: trans('media.also_known_as'), value: props.game.extra.alternativeTitles?.join(', ') || null },
    { label: trans('media.engines'), value: list('engines') },
]);

// IGDB "time to beat" tiles (rushed / average / completionist) — the game's
// counterpart of a movie's runtime, shown as its own section (not a chip).
const timeToBeat = computed<FactTile[]>(() => {
    const times = props.game.extra.timeToBeat;

    if (!times) {
        return [];
    }

    return [
        { key: 'hastily', icon: Timer, label: trans('media.rushed'), value: formatRuntime(times.hastily ?? null) },
        { key: 'normally', icon: Gauge, label: trans('media.average_playthrough'), value: formatRuntime(times.normally ?? null) },
        { key: 'completely', icon: Trophy, label: trans('media.completionist'), value: formatRuntime(times.completely ?? null) },
    ].filter((tile): tile is FactTile => tile.value !== null);
});

// The header's score is critics and players together; hovering it tells them apart.
const ratingHint = computed<TooltipRow[]>(() =>
    [
        { label: trans('media.critics_rating'), value: providerRatingLabel(props.game.extra.criticsRating, 'media.count_critic_reviews') },
        { label: trans('media.players_rating'), value: providerRatingLabel(props.game.extra.playersRating, 'media.count_player_ratings') },
    ].filter((row): row is TooltipRow => row.value !== null),
);

// This game is a DLC / edition of a base game — link back to it, like a season
// links up to its show.
const parentGameRef = computed(() => props.game.extra.parentGameRef ?? null);
const parentGameTitle = computed(() => props.game.extra.parentGameTitle ?? null);

// An edition of this game in the viewer's library is said here as a fact,
// never counted as this game: what was played was that edition.
const ownedEdition = computed(() => {
    for (const edition of props.game.extra.editions ?? []) {
        const status = shownStatus(shownState(stateKey(edition)), false);

        if (status !== null) {
            return {
                href: mediaUrl(edition),
                text: trans('media.your_edition_status', { edition: edition.title, status: shownStatusLabel(status) }),
            };
        }
    }

    return null;
});
</script>

<template>
    <Head :title="game.title" />

    <TitlePageLayout :from-library="fromLibrary" :removed-at-source="removedAtSource">
        <template #hero>
            <MediaHero :backdrop-path="game.backdropPath" :videos="game.videos" />
        </template>

        <template #header>
            <!-- A DLC / edition links back to its base game under the title,
                 like a season links up to its show. -->
            <MediaTitleHeader
                :details="game"
                :chips="chips"
                :detail-rows="detailRows"
                :rating-hint="ratingHint"
                :parent-link="
                    parentGameRef
                        ? { href: mediaUrl({ type: 'game', ref: parentGameRef, provider: game.provider }), label: parentGameTitle ?? '' }
                        : null
                "
            />
        </template>

        <template #panels>
            <!-- One unit with the status card: the edition note sits right under it,
                 not a panel's distance away. -->
            <div class="flex flex-col gap-2">
                <MediaStatusPanel :media="game" />
                <Link
                    v-if="ownedEdition"
                    :href="ownedEdition.href"
                    class="text-ink-secondary hover:text-ink block px-1 text-sm font-semibold underline-offset-4 transition-colors duration-150 hover:underline"
                >
                    {{ ownedEdition.text }}
                </Link>
            </div>
            <StickyActionsPanel :media="game" />
        </template>

        <OverviewSection :overview="game.overview" :people="companyPeople" />

        <FactTilesSection :title="trans('media.time_to_beat')" :tiles="timeToBeat" type="game" />

        <GameStoresSection :stores="game.extra.stores ?? []" />
        <GameLanguagesSection :languages="game.extra.languages ?? []" />

        <!-- The storyline beside the summary, in a card as the page's other facts are. -->
        <section v-if="game.extra.storyline">
            <SectionHeading :title="trans('media.storyline')" />
            <div class="border-line-subtle bg-surface rounded-2xl border px-5 py-4">
                <ClampedText :text="game.extra.storyline" :lines="4" />
            </div>
        </section>

        <VideosSection :videos="game.videos" />
        <PhotosSection :backdrops="game.backdrops" :posters="game.posters" />

        <template #below>
            <CollectionBanner v-if="collection" :collection="collection" :fallback-backdrop="game.backdropPath" />
            <!-- What a bundle holds, the game's editions, its own DLC and
                 expansions, then its other releases (bundles, remakes, ports…). -->
            <TitleShelf :title="trans('media.in_this_bundle')" :items="game.extra.bundleContents ?? []" />
            <TitleShelf :title="trans('media.editions')" :items="game.extra.editions ?? []" />
            <TitleShelf :title="trans('media.dlcs')" :items="game.extra.dlcs ?? []" />
            <TitleShelf :title="trans('media.other_releases')" :items="game.extra.otherReleases ?? []" />
            <RelatedTitles :titles="otherMedia ?? []" :heading="trans('media.in_other_media')" />
            <!-- Asked of the provider after the page is drawn. -->
            <EventShelf :title="trans('media.shown_at')" :events="shownAt ?? []" />
        </template>

        <template #after>
            <!-- Similar games show about ten, rounded up to a whole row. -->
            <RecommendationsBand :related="game.related" :related-title="trans('media.similar_games')" :shelf-size="10" />
        </template>

        <template #recent>
            <RecentlyViewed :title="game.title" :type="game.type" :backdrop-path="game.backdropPath" :items="recentlyViewed ?? []" />
        </template>
    </TitlePageLayout>
</template>
