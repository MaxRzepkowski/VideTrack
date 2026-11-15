<script setup lang="ts">
import CardCaption from '@/components/media/CardCaption.vue';
import { useCreatorState } from '@/composables/useCreatorState';
import { useEntityFollow } from '@/composables/useEntityFollow';
import { useMediaModals } from '@/composables/useMediaModals';
import { creatorOf, type Creator } from '@/lib/creators';
import { departmentLabel, isDepartment } from '@/lib/departments';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { POSTER_CARD_SIZES } from '@/lib/ui';
import { mediaUrl } from '@/lib/urls';
import type { MediaSummary } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Bell, BellRing, Building2, List, RadioTower, User } from 'lucide-vue-next';
import { computed } from 'vue';

// A person, a studio / publisher or a network as a card — search results,
// list positions, discover rows. Creators carry no watched / rating state:
// their two actions are custom-list membership and the bell, the same pair
// their page offers.
const props = defineProps<{ media: MediaSummary }>();

// Rendered for the summaries `creatorOf` reads as a creator.
const creator = computed<Creator>(() => creatorOf(props.media) ?? { kind: 'person', provider: props.media.provider, ref: props.media.ref });
const isPerson = computed(() => creator.value.kind === 'person');

const state = useCreatorState(() => creator.value);
const inList = computed(() => state.value.listIds.length > 0);

// Names the action, not the state the button is already in — same rule as the
// media action bar, since it is the same control.
const listsTitle = computed(() => (inList.value ? trans('media.manage_lists') : trans('media.add_to_list')));

const { openCreatorLists } = useMediaModals();
const { followed, busy: followBusy, label: followTitle, toggle: toggleFollow } = useEntityFollow(() => creator.value);

const image = computed(() => mediaImage(props.media.posterPath, 'w342'));
const href = computed(() =>
    isPerson.value ? route('person.details', { provider: creator.value.provider, ref: creator.value.ref }) : mediaUrl(props.media),
);

// A person: the titles TMDB knows them for, otherwise their department.
const subtitle = computed(() => {
    if (!isPerson.value) {
        return undefined;
    }

    const knownFor = props.media.extra.knownFor;

    if (Array.isArray(knownFor) && knownFor.length > 0) {
        return knownFor.join(', ');
    }

    const department = props.media.extra.knownForDepartment;

    return isDepartment(department) ? departmentLabel(department) : trans('media.person');
});

// Corner badge over a company's logo (like a media card's type badge),
// coloured by the media type the company makes, as the server says, so it
// reads as a sibling of the type badges: TV networks purple (shows), game
// dev/publishers green (games), film/TV studios blue (movies). Labelled by
// that type too — a game company is a "developer / publisher", never a film
// "studio".
const kindBadge = computed(() => {
    if (creator.value.kind === 'network') {
        return { label: trans('media.network'), icon: RadioTower, background: 'bg-media-tv' };
    }

    const makes = props.media.extra.makes;
    const meta = makes ? mediaTypeMeta(makes) : null;

    if (meta?.maker) {
        return { label: meta.maker.label(), icon: meta.maker.icon, background: meta.background };
    }

    return { label: trans('media.studio'), icon: Building2, background: 'bg-media-movie' };
});
</script>

<template>
    <div data-tooltip-group class="group flex flex-col">
        <div
            class="border-line-subtle bg-surface group-hover:border-brand xs:rounded-2xl flex flex-col overflow-hidden rounded-xl border transition-colors duration-150"
        >
            <Link :href="href" class="block">
                <div v-if="isPerson" class="bg-surface-raised aspect-poster relative w-full overflow-hidden">
                    <img
                        v-if="image"
                        :src="image"
                        :srcset="mediaImageSrcSet(media.posterPath, [185, 342])"
                        :sizes="POSTER_CARD_SIZES"
                        :alt="media.title"
                        width="342"
                        height="513"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="from-surface-raised to-line flex h-full w-full items-center justify-center bg-gradient-to-br">
                        <User class="text-ink-muted size-10" />
                    </div>
                </div>
                <!-- Provider logos are drawn for a light background — white when
                     one is present, dark surface for the icon fallback. -->
                <div
                    v-else
                    class="aspect-poster relative flex w-full items-center justify-center overflow-hidden p-6"
                    :class="image ? 'bg-paper' : 'bg-surface-raised'"
                >
                    <img
                        v-if="image"
                        :src="image"
                        :alt="media.title"
                        width="342"
                        height="342"
                        loading="lazy"
                        class="max-h-full max-w-full object-contain"
                    />
                    <Building2 v-else class="text-ink-muted size-10" />
                    <span
                        class="text-ink shadow-card xs:top-3 xs:right-3 xs:gap-1.5 xs:px-2.5 xs:py-1 absolute top-2 right-2 flex items-center gap-1 rounded-md px-1.5 py-0.5 text-sm font-extrabold"
                        :class="kindBadge.background"
                    >
                        <component :is="kindBadge.icon" class="xs:size-3.75 size-3" :stroke-width="2" />
                        {{ kindBadge.label }}
                    </span>
                </div>
            </Link>
            <div class="border-surface-overlay bg-surface-raised flex items-stretch border-y">
                <button
                    v-tooltip="listsTitle"
                    type="button"
                    aria-haspopup="dialog"
                    class="flex min-w-0 flex-1 items-center justify-center py-2.5 transition-colors duration-150"
                    :class="inList ? 'bg-action-watchlist' : 'hover:bg-highlight/5'"
                    @click="openCreatorLists(creator, media.title)"
                >
                    <List
                        class="size-6 transition-colors duration-150"
                        :class="inList ? 'text-surface-base' : 'text-action-watchlist'"
                        :stroke-width="2.2"
                    />
                </button>
                <div class="bg-surface-overlay w-px"></div>
                <button
                    v-tooltip="followTitle"
                    type="button"
                    :disabled="followBusy"
                    :aria-pressed="followed"
                    class="flex min-w-0 flex-1 items-center justify-center py-2.5 transition-colors duration-150"
                    :class="followed ? 'bg-brand' : 'hover:bg-highlight/5'"
                    @click="toggleFollow"
                >
                    <!-- White on the fill, not `surface-base`: the bell's
                         purple is dark, unlike the list button's cyan. -->
                    <component
                        :is="followed ? BellRing : Bell"
                        class="size-6 transition-colors duration-150"
                        :class="followed ? 'text-on-accent' : 'text-brand-soft'"
                        :stroke-width="2.2"
                    />
                </button>
            </div>
            <CardCaption :title="media.title" :subtitle="subtitle" :href="href" :subtitle-href="href" />
        </div>
    </div>
</template>
