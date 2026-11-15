<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import CertificationModal from '@/components/media/CertificationModal.vue';
import DetailsModal from '@/components/media/DetailsModal.vue';
import MediaTypeBadge from '@/components/media/MediaTypeBadge.vue';
import { countryName } from '@/lib/format';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { mediaTypeDefinition } from '@/lib/mediaTypes';
import { ratingPercent } from '@/lib/ratings';
import type { TooltipRow } from '@/lib/tooltip';
import type { TitleExtra } from '@/types/extras';
import type { CertificationRow, Chip, DetailRow, MediaDetails } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Star } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        details: MediaDetails<TitleExtra>;
        /** Formatted text chips after the type badge (runtime, certification, seasons…). */
        chips: Chip[];
        detailRows: DetailRow[];
        /** "Back up" link under the title (a DLC → its base game), like a season → its show. */
        parentLink?: { href: string; label: string } | null;
        /** What the score is made of (a game's critics and players), shown on hover like a list's statistics. */
        ratingHint?: TooltipRow[] | null;
    }>(),
    { parentLink: null, ratingHint: null },
);

const poster = computed(() => mediaImage(props.details.posterPath, 'w500'));

// Genres link to /discover genre pages only where the type's provider can list by genre.
const genreLinks = computed(() => mediaTypeDefinition(props.details.type).genreBrowsable);

// Networks (with logos) live in the details extra — only shows carry them.
const networks = computed(() => props.details.extra.networks ?? undefined);

// Production companies (studios) — movies and shows both carry them; shown as
// clickable logo chips in the details modal, next to networks.
const studios = computed(() => props.details.extra.studios ?? undefined);

// The chosen chip's own region and meaning, plus the film's content
// descriptors — all three describe THE selected classification, which the
// typed list of every country's classification does not model.
const certificationRegion = computed(() => props.details.extra.certificationRegion ?? null);
const certificationMeaning = computed(() => props.details.extra.certificationMeaning ?? null);
const certificationDescriptors = computed(() => props.details.extra.certificationDescriptors ?? []);

// A film's classifications by country, the viewer's own carrying its content descriptors.
const filmCertificationRows = computed<CertificationRow[]>(() =>
    props.details.certifications
        .map((entry) => ({
            key: `${entry.region}-${entry.code}`,
            name: countryName(entry.region),
            code: entry.code,
            meaning: entry.meaning,
            descriptors: entry.region === certificationRegion.value ? certificationDescriptors.value.map((text) => ({ text, image: null })) : [],
            synopsis: null,
            image: null,
        }))
        .sort((a, b) => a.name.localeCompare(b.name)),
);

// A game's developers, publishers, porting and supporting studios as ordinary detail rows, but with each
// company name linking to its page. Only rows that actually have companies.
const creatorRows = computed(() => {
    const rows = [
        { label: trans('media.developers'), creators: props.details.extra.developerLinks ?? [] },
        { label: trans('media.publishers'), creators: props.details.extra.publisherLinks ?? [] },
        { label: trans('media.porting_studios'), creators: props.details.extra.portingLinks ?? [] },
        { label: trans('media.supporting_studios'), creators: props.details.extra.supportingLinks ?? [] },
    ].filter((row) => row.creators.length > 0);

    return rows.length ? rows : undefined;
});
</script>

<template>
    <!-- Poster overlaps the hero (250×375, -mt-37.5). Below md the
         poster stacks above the title instead of sitting beside it. -->
    <div class="flex flex-col items-start gap-8 md:flex-row">
        <div
            class="bg-surface shadow-scrim/60 relative z-20 -mt-24 h-60 w-40 shrink-0 overflow-hidden rounded-2xl shadow-2xl sm:-mt-37.5 sm:h-93.75 sm:w-62.5"
        >
            <img
                v-if="poster"
                :src="poster"
                :srcset="mediaImageSrcSet(details.posterPath, [185, 342, 500])"
                sizes="(min-width: 640px) 250px, 160px"
                :alt="details.title"
                width="500"
                height="750"
                loading="lazy"
                class="h-full w-full object-cover"
            />
            <div v-else class="from-surface-raised to-line h-full w-full bg-gradient-to-br"></div>
        </div>

        <div class="w-full min-w-0 md:flex-1 md:pt-10">
            <!-- The details button sits beside the heading, not in it: a
                 screen reader would read its label as part of the title. The
                 heading lends its title and year to this row (contents), so
                 the button wraps beside the year as one of the row's items. -->
            <div class="flex flex-wrap items-center gap-3 leading-tight">
                <h1 class="m-0 contents">
                    <span class="sm:text-hero text-3xl font-semibold wrap-break-word">{{ details.title }}</span>
                    <span v-if="details.year" class="text-ink-muted text-2xl font-semibold">({{ details.year }})</span>
                </h1>
                <DetailsModal
                    :title="trans('media.details')"
                    :rows="detailRows"
                    :keywords="details.keywords"
                    :keyword-type="details.type"
                    :links="details.externalLinks"
                    :networks="networks"
                    :studios="studios"
                    :creator-rows="creatorRows"
                    :refresh="{ url: route('media.refresh'), data: { type: details.type, ref: details.ref } }"
                />
            </div>

            <Link
                v-if="parentLink"
                :href="parentLink.href"
                class="text-ink-muted hover:text-brand-soft mt-1 inline-block text-lg font-semibold transition-colors duration-150"
            >
                ← {{ parentLink.label }}
            </Link>

            <!-- Genres read as a line of links, not as chips: no border, no
                 fill, no rounding. They point at the /discover genre pages (by
                 name; the backend resolves names in any supported locale), and
                 the hairline after each one keeps a wrapped line from opening
                 with a dangling rule. -->
            <div v-if="details.genres.length" class="mt-2 flex flex-wrap items-center gap-y-1 text-sm font-semibold">
                <span v-for="(genre, index) in details.genres" :key="genre" class="inline-flex items-center">
                    <Link
                        v-if="genreLinks"
                        :href="route('discover', { type: details.type, genre })"
                        class="text-ink-secondary hover:text-brand-soft transition-colors duration-150"
                    >
                        {{ genre }}
                    </Link>
                    <span v-else class="text-ink-secondary">{{ genre }}</span>
                    <Hairline v-if="index < details.genres.length - 1" />
                </span>
            </div>

            <div class="text-ink-secondary mt-3 flex flex-wrap items-center gap-3 text-sm font-semibold">
                <MediaTypeBadge :type="details.type" />
                <template v-for="chip in chips" :key="typeof chip === 'string' ? chip : chip.label">
                    <span v-if="typeof chip === 'string'" class="border-line-strong bg-scrim/30 rounded-md border px-2.5 py-1">
                        {{ chip }}
                    </span>
                    <button
                        v-else-if="'onClick' in chip"
                        type="button"
                        class="border-line-strong hover:border-brand hover:text-ink bg-scrim/30 rounded-md border px-2.5 py-1 transition-colors duration-150"
                        @click="chip.onClick"
                    >
                        {{ chip.label }}
                    </button>
                    <CertificationModal v-else :label="chip.label" :meaning="certificationMeaning" :rows="chip.rows ?? filmCertificationRows" />
                </template>
                <span
                    v-if="details.communityRating"
                    v-tooltip="ratingHint?.length ? ratingHint : undefined"
                    :data-tooltip-tap="ratingHint?.length ? '' : undefined"
                    :tabindex="ratingHint?.length ? 0 : undefined"
                    class="focus-visible:ring-ink-muted inline-flex items-center gap-1.5 rounded-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    :class="ratingHint?.length ? 'cursor-pointer' : ''"
                >
                    <Star class="fill-action-rating text-action-rating size-3.75" :stroke-width="1" />
                    <span class="text-action-rating font-bold">{{ ratingPercent(details.communityRating) }}</span>
                </span>
            </div>

            <!-- The tagline is the title's own voice, not another fact about it:
                 a wider gap sets it apart from the badge row above. -->
            <p v-if="details.tagline" class="text-ink-muted m-0 mt-6 text-lg italic">{{ details.tagline }}</p>
        </div>
    </div>
</template>
