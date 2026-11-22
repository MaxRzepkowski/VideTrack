<script setup lang="ts">
import PageMasthead from '@/components/PageMasthead.vue';
import ActivityPanel from '@/components/users/ActivityPanel.vue';
import UserHeader from '@/components/users/UserHeader.vue';
import { confirm } from '@/composables/useConfirm';
import { useMediaActions } from '@/composables/useMediaActions';
import { useReloadAfterWrites } from '@/composables/useReloadAfterWrites';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ActivitySort } from '@/types/generated';
import type { ActivityEntry, ActivityFilters, ProfileData } from '@/types/media';
import type { PageInfo } from '@/types/pagination';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

type ActivityPanelName = 'history' | 'ratings' | 'favorites';

/**
 * The three log panels of a user view — history, ratings and favorites. They
 * differ in what an entry is and how it leaves the panel; the page around
 * them is one.
 */
const props = defineProps<{
    panel: ActivityPanelName;
    profile: ProfileData;
    isOwner: boolean;
    entries: ActivityEntry[];
    pagination: PageInfo | null;
    /** What each day cost, per unit — history is the only panel with a cost. */
    dayTotals?: Record<string, Record<string, number>>;
    filters: ActivityFilters;
    genreOptions: string[];
    typeOptions: string[];
    sortOptions: ActivitySort[];
    ownerRatingsShown: boolean;
}>();

const { deletePlay, rate, setFavorite } = useMediaActions();

const PANELS = {
    history: {
        title: () => trans('users.name_history', { name: props.profile.name }),
        heading: () => trans('common.history'),
        routeName: 'users.history',
        sortLabel: () => trans('users.mark_date'),
        withStatus: true,
        withHideRated: true,
        showPaginationSummary: false,
        removeLabel: () => trans('common.remove_this_play'),
        removeConfirm: () => trans('media.remove_play_confirm'),
        // Every entry, the day totals and the cards' states follow the history.
        reload: ['entries', 'pagination', 'dayTotals', 'userStates'],
        // A rolled-up card (a whole show) has no single play to remove.
        remove: (entry: ActivityEntry) => (entry.id === null ? undefined : deletePlay(entry.media, entry.id)),
    },
    ratings: {
        title: () => trans('users.name_ratings', { name: props.profile.name }),
        heading: () => trans('common.ratings'),
        routeName: 'users.ratings',
        sortLabel: () => trans('users.activity_date'),
        withStatus: false,
        // Everything here is rated by the owner; a visitor hides what they rated themselves.
        withHideRated: !props.isOwner,
        showPaginationSummary: false,
        removeLabel: () => trans('common.remove_rating'),
        removeConfirm: () => trans('users.remove_rating_confirm'),
        // An entry leaves or moves with its rating.
        reload: ['entries', 'pagination', 'userStates'],
        remove: (entry: ActivityEntry) => rate(entry.media, null),
    },
    favorites: {
        title: () => trans('users.name_favorites', { name: props.profile.name }),
        heading: () => trans('common.favorites'),
        routeName: 'users.favorites',
        // A favorite has no date beyond when it was added.
        sortLabel: () => null,
        withStatus: false,
        withHideRated: true,
        showPaginationSummary: true,
        removeLabel: () => trans('common.remove_from_favorites'),
        removeConfirm: () => trans('users.remove_favorite_confirm'),
        // An entry leaves with its heart.
        reload: ['entries', 'pagination', 'userStates'],
        remove: (entry: ActivityEntry) => setFavorite(entry.media, false),
    },
} as const;

const config = PANELS[props.panel];

useReloadAfterWrites([...config.reload]);

async function remove(entry: ActivityEntry): Promise<void> {
    if (await confirm({ message: config.removeConfirm(), confirmLabel: trans('users.remove') })) {
        void config.remove(entry);
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="config.title()" />

        <!-- The lit tab is this page's title, so no line repeats it in words.
             What it cannot carry — a heading for a screen reader — stays, out
             of sight; the count in the strip below says the rest. -->
        <h1 class="sr-only">{{ config.heading() }}</h1>

        <PageMasthead class="pb-4">
            <UserHeader :profile="profile" :active="panel" identity="link" />
        </PageMasthead>

        <!-- The panel brings its own band and results container. -->
        <ActivityPanel
            :route-name="config.routeName"
            :username="profile.username"
            :owner-name="profile.name"
            :is-owner="isOwner"
            :entries="entries"
            :pagination="pagination"
            :day-totals="dayTotals"
            :filters="filters"
            :genre-options="genreOptions"
            :type-options="typeOptions"
            :sort-label="config.sortLabel()"
            :with-status="config.withStatus"
            :with-hide-rated="config.withHideRated"
            :sort-options="sortOptions"
            :owner-ratings-shown="ownerRatingsShown"
            :show-pagination-summary="config.showPaginationSummary"
            :remove-label="config.removeLabel()"
            @remove="remove"
        />
    </AppLayout>
</template>
