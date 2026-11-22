<script setup lang="ts">
import MenuRow from '@/components/MenuRow.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { onListsText } from '@/lib/lists';
import { mediaTypeDefinition } from '@/lib/mediaTypes';
import { finishedOn, playActionLabels } from '@/lib/progress';
import { ratingPercent, ratingScore } from '@/lib/ratings';
import { shownStatus, shownStatusVisual, STATUS_VISUALS } from '@/lib/status';
import { rowTint } from '@/lib/ui';
import type { MediaSummary } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { Heart, List, MoreHorizontal, Star } from '@lucide/vue';
import { DropdownMenuArrow, DropdownMenuItem } from 'radix-vue';
import { computed } from 'vue';

const props = defineProps<{
    media: MediaSummary;
}>();

const { stateOf, toggleFavorite, markWatchedIfUntouched, toggleWatchlist } = useMediaActions();
const { openWatched, openRate, openLists } = useMediaModals();

const state = stateOf(() => props.media);

const inList = computed(() => state.value.listIds.length > 0);

// Container with at least one episode watched: an in-progress state, shown
// exactly like the status card — the `CirclePlay` icon on purple. Deliberately
// NOT the pointer-based `inProgress` (a 0/N "up next" season must not light up
// as partially watched).
const partiallyWatched = computed(() => !state.value.watched && (state.value.watchedEpisodes ?? 0) > 0);

// The watched cell mirrors the status card: dropped / on-hold / in-progress
// each take over with the status icon and its colour. A manually in-progress
// game and a partially watched container share the purple `CirclePlay`; a
// click opens the matching modal.
const status = computed(() => state.value.status);

function openModal(): void {
    openWatched(props.media, status.value === 'dropped' || status.value === 'on_hold' ? status.value : 'watched');
}

// In the status card's order: a status outranks being watched, so a finished
// game started again reads as in progress, not as done.
const watchedVisual = computed(() => {
    const shown = shownStatus(state.value, !mediaTypeDefinition(props.media.type).playable);

    return shown === null ? null : shownStatusVisual(shown);
});

const watchedIcon = computed(() => (watchedVisual.value ?? STATUS_VISUALS.watched).icon);

const watchedBackground = computed(() => watchedVisual.value?.color);

// Every label names the action the click performs, never the state the cell is
// already in ("Watched", "On 3 lists"). The state is carried by the colour and
// the icon; a name that describes it would leave both sighted and screen-reader
// users guessing what pressing the button actually does. A finished title adds
// when, above the action: the colour says done, not since when.
const watchedTitle = computed(() => {
    if (status.value !== null || state.value.watched || partiallyWatched.value) {
        const finished = status.value === null ? finishedOn(props.media, state.value) : null;

        return finished === null ? trans('media.change_status') : `${finished}\n${trans('media.change_status')}`;
    }

    const labels = playActionLabels(props.media);

    return labels.start ?? labels.mark;
});

// The rating cell always shows the community average — the user's own rating
// never replaces it (it lives on the poster badge and in the details panel).
const ratingLabel = computed(() => ratingPercent(props.media.communityRating) ?? trans('common.n_a'));

const listsTitle = computed(() => (inList.value ? trans('media.manage_lists') : trans('media.add_to_list')));

const favoriteTitle = computed(() => (state.value.favorite ? trans('common.remove_from_favorites') : trans('media.add_to_favorites')));

// The cell shows the community average, but clicking it opens the rate modal —
// so the label is the action, in both the rated and the unrated state.
const ratingTitle = computed(() => trans('media.rate'));

// Under each row of the narrow card's menu, where the title stands with it: the marks it sets are folded away there.
const ratingState = computed(() =>
    state.value.rating === null ? trans('media.not_rated') : trans('media.rated_score', { score: ratingScore(state.value.rating) }),
);

const listsState = computed(() => onListsText(state.value.listIds.length) ?? trans('media.on_no_list'));
</script>

<template>
    <!-- `v-tooltip` doubles as the accessible name on the three icon-only
         cells; the rating cell already shows a number, so it names itself.
         Three of the four open a dialog rather than toggling anything, so
         they carry `aria-haspopup` instead of `aria-pressed`; favourite
         toggles for real, but its name already flips add/remove, and pairing
         that with `aria-pressed` would state it twice, in conflicting words. -->
    <!-- Narrow cards keep the status cell in the open — it is the action the
         bar exists for — and fold rating, lists and favourites into a menu
         beside it. The community average is not lost with them: it reads off
         the poster, in the marker strip (`MediaStateMarkers`). -->
    <!-- One root holds both variants so a caller's class (`mt-auto` on the
         episode cards) lands on the bar instead of being dropped. -->
    <div>
        <div class="border-surface-overlay bg-surface-raised xs:hidden grid grid-cols-2 items-stretch border-y">
            <button
                v-tooltip="watchedTitle"
                type="button"
                aria-haspopup="dialog"
                class="border-surface-overlay hover:bg-highlight/5 flex min-w-0 items-center justify-center border-r py-2.5 transition-colors duration-150"
                :style="watchedBackground ? { backgroundColor: watchedBackground } : undefined"
                @click="openModal"
            >
                <component
                    :is="watchedIcon"
                    class="size-6 transition-colors duration-150"
                    :class="status !== null || state.watched || partiallyWatched ? 'text-surface-base' : 'text-action-watched'"
                    :stroke-width="2.2"
                />
            </button>

            <DropdownMenu>
                <DropdownMenuTrigger
                    v-tooltip="trans('common.more_options')"
                    class="text-ink-secondary hover:text-ink hover:bg-highlight/5 data-[state=open]:bg-surface-overlay data-[state=open]:text-ink flex min-w-0 items-center justify-center py-2.5 transition-colors duration-150"
                >
                    <MoreHorizontal class="size-6" :stroke-width="2.2" />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="center"
                    :collision-padding="12"
                    sticky="always"
                    :prioritize-position="true"
                    class="border-line-strong bg-surface w-64 max-w-[calc(100vw-1.5rem)] overflow-visible rounded-xl px-0 py-1.5 shadow-lg"
                >
                    <!-- Each row wears the colour of the mark it sets and says where the title stands with it. -->
                    <DropdownMenuItem as-child>
                        <MenuRow :icon="Star" :label="ratingTitle" :description="ratingState" :style="rowTint('rating')" @click="openRate(media)" />
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <MenuRow :icon="List" :label="listsTitle" :description="listsState" :style="rowTint('watchlist')" @click="openLists(media)" />
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <MenuRow
                            :icon="Heart"
                            :label="favoriteTitle"
                            :description="state.favorite ? trans('media.in_favorites') : undefined"
                            :style="rowTint('favorite')"
                            @click="toggleFavorite(media)"
                        />
                    </DropdownMenuItem>
                    <!-- The nose points at the button that opened the menu; its open side lets the panel's edge through. -->
                    <DropdownMenuArrow :width="18" :height="8" class="-mt-px overflow-visible">
                        <path d="M0 0 L15 10 L30 0" class="fill-surface stroke-line-strong" vector-effect="non-scaling-stroke" />
                    </DropdownMenuArrow>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div class="border-surface-overlay bg-surface-raised xs:flex hidden items-stretch border-y">
            <button
                v-tooltip="watchedTitle"
                type="button"
                aria-haspopup="dialog"
                class="hover:bg-highlight/5 flex min-w-0 flex-1 items-center justify-center py-2.5 transition-colors duration-150"
                :style="watchedBackground ? { backgroundColor: watchedBackground } : undefined"
                @click="openModal"
                @contextmenu.prevent="markWatchedIfUntouched(media)"
            >
                <component
                    :is="watchedIcon"
                    class="size-6 transition-colors duration-150"
                    :class="status !== null || state.watched || partiallyWatched ? 'text-surface-base' : 'text-action-watched'"
                    :stroke-width="2.2"
                />
            </button>
            <div class="bg-surface-overlay w-px"></div>
            <button
                v-tooltip="listsTitle"
                type="button"
                aria-haspopup="dialog"
                class="hover:bg-highlight/5 flex min-w-0 flex-1 items-center justify-center py-2.5 transition-colors duration-150"
                :style="inList ? { backgroundColor: 'var(--color-action-watchlist)' } : undefined"
                @click="openLists(media)"
                @contextmenu.prevent="toggleWatchlist(media)"
            >
                <List
                    class="size-6 transition-colors duration-150"
                    :class="inList ? 'text-surface-base' : 'text-action-watchlist'"
                    :stroke-width="2.2"
                />
            </button>
            <div class="bg-surface-overlay w-px"></div>
            <button
                v-tooltip="favoriteTitle"
                type="button"
                class="hover:bg-highlight/5 flex min-w-0 flex-1 items-center justify-center py-2.5 transition-colors duration-150"
                :style="state.favorite ? { backgroundColor: 'var(--color-action-favorite)' } : undefined"
                @click="toggleFavorite(media)"
            >
                <Heart
                    class="size-6 transition-colors duration-150"
                    :class="state.favorite ? 'text-surface-base' : 'text-action-favorite'"
                    :stroke-width="2.2"
                />
            </button>
            <div class="bg-surface-overlay w-px"></div>
            <button
                v-tooltip="ratingTitle"
                type="button"
                :aria-label="ratingTitle"
                aria-haspopup="dialog"
                class="hover:bg-highlight/5 @container flex min-w-0 flex-1 items-center justify-center gap-1 px-1 py-2.5 transition-colors duration-150"
                @click="openRate(media)"
            >
                <!-- Below `lg` the cell is the star alone. From `lg` the number
                     joins it only while the cell itself has room for both: a
                     grid squeezed by a sidebar (discover's filters) goes back
                     to the star until it widens again. A four-character "100%"
                     steps down to 14px and still needs a little more room.
                     Thresholds are measured in Roboto Flex bold: star 24px +
                     gap 4px + "85%" 33px / "100%" 37px, less the 8px padding
                     the number may run into. -->
                <Star class="fill-action-rating text-action-rating size-6 shrink-0" :stroke-width="1" />
                <span
                    class="text-on-photo hidden font-bold whitespace-nowrap"
                    :class="ratingLabel.length > 3 ? 'text-sm lg:@min-[3.5rem]:inline' : 'text-base lg:@min-[3.2rem]:inline'"
                    >{{ ratingLabel }}</span
                >
            </button>
        </div>
    </div>
</template>
