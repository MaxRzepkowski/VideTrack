<script setup lang="ts">
import UserRatingBadge from '@/components/media/UserRatingBadge.vue';
import { useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { trackedBecause } from '@/lib/tracking';
import type { MediaSummary } from '@/types/media';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Bell, BellOff, BellRing, Heart, List, Star } from '@lucide/vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        media: MediaSummary;
        /** Rate row label; season pages pass "Rate this season". */
        rateLabel?: string | null;
    }>(),
    { rateLabel: null },
);

const { stateOf, toggleFavorite, cycleFollow, toggleWatchlist } = useMediaActions();
const { openRate, openLists } = useMediaModals();

const state = stateOf(() => props.media);

const inList = computed(() => state.value.listIds.length > 0);

// The bell lives on hierarchy roots alone — season/episode events roll up.
const followable = computed(() => !['season', 'episode'].includes(props.media.type));

// Why the tracking matrix covers the title on its own, as the server read it.
const trackedAs = computed(() => trackedBecause(state.value.followSource));

// The bell names why it rings; a click walks it on (`nextFollowMode`), worked
// out from the server's state when the request goes out. A status, the backlog
// or history outranks a bell, so the bell names them first — as the calendar
// reads them.
const followRow = computed(() => {
    const mode = state.value.follow;

    if (mode === 'mute') {
        return { icon: BellOff, label: trans('common.muted'), active: false };
    }

    if (trackedAs.value !== null) {
        return { icon: Bell, label: trackedAs.value, active: true };
    }

    if (mode === 'follow') {
        const label = state.value.followVia ? trans('media.following_name', { name: state.value.followVia }) : trans('common.following');

        return { icon: BellRing, label, active: true };
    }

    return { icon: Bell, label: trans('common.follow_releases'), active: false };
});

// The watched / status surface is the status card above this panel; here
// only the states that follow it — rating, lists, favorites.
const rows = computed(() => [
    {
        icon: Star,
        label: state.value.rating !== null ? trans('media.your_rating') : (props.rateLabel ?? trans('media.rate_this_title')),
        ratingValue: state.value.rating,
        active: state.value.rating !== null,
        background: 'var(--color-action-rating)',
        // The rate star stays an outline in both states (deliberately
        // different from the filled list/favorite icons): yellow when
        // unrated, black on the amber row once rated.
        iconClass: 'text-action-rating',
        activeIconClass: 'text-surface-base',
        activeTextClass: 'text-surface-base',
        strokeWidth: 2,
        opensDialog: true,
        onClick: () => openRate(props.media),
    },
    {
        icon: List,
        label: inList.value
            ? transChoice('media.on_count_list', state.value.listIds.length, {
                  count: String(state.value.listIds.length),
              })
            : trans('media.add_to_list'),
        ratingValue: null,
        active: inList.value,
        background: 'var(--color-action-watchlist)',
        iconClass: 'text-action-watchlist',
        activeIconClass: 'text-surface-base',
        activeTextClass: 'text-surface-base',
        strokeWidth: 2.2,
        opensDialog: true,
        onClick: () => openLists(props.media),
        onRightClick: () => toggleWatchlist(props.media),
    },
    {
        icon: Heart,
        label: state.value.favorite ? trans('media.in_favorites') : trans('media.add_to_favorites'),
        ratingValue: null,
        active: state.value.favorite,
        background: 'var(--color-action-favorite)',
        iconClass: 'text-action-favorite',
        activeIconClass: 'text-surface-base',
        activeTextClass: 'text-surface-base',
        strokeWidth: 2.2,
        opensDialog: false,
        onClick: () => toggleFavorite(props.media),
    },
    ...(followable.value
        ? [
              {
                  icon: followRow.value.icon,
                  label: followRow.value.label,
                  ratingValue: null,
                  active: followRow.value.active,
                  background: 'var(--color-brand)',
                  iconClass: 'text-brand',
                  activeIconClass: 'text-on-photo',
                  activeTextClass: 'text-on-photo',
                  strokeWidth: 2.2,
                  opensDialog: false,
                  onClick: () => cycleFollow(props.media),
              },
          ]
        : []),
]);

// Right-click shortcuts (mark now / toggle watchlist) skip the modal; rows
// without a shortcut keep the native context menu.
function onRowContextMenu(row: { onRightClick?: () => void }, event: MouseEvent): void {
    if (row.onRightClick) {
        event.preventDefault();
        row.onRightClick();
    }
}
</script>

<template>
    <!-- Stickiness lives on the page-level wrapper shared with the status card -->
    <div class="border-line-subtle bg-surface flex flex-col gap-2 rounded-2xl border p-2">
        <button
            v-for="(row, index) in rows"
            :key="index"
            type="button"
            :aria-pressed="row.opensDialog ? undefined : row.active"
            :aria-haspopup="row.opensDialog ? 'dialog' : undefined"
            class="flex items-center gap-3 rounded-xl px-4 transition-colors duration-150"
            :class="[row.active ? '' : 'hover:bg-highlight/5', row.ratingValue !== null ? 'py-1.5' : 'py-3.5']"
            :style="row.active ? { backgroundColor: row.background } : undefined"
            @click="row.onClick"
            @contextmenu="onRowContextMenu(row, $event)"
        >
            <component
                :is="row.icon"
                class="size-6 shrink-0 transition-colors duration-150"
                :class="row.active ? row.activeIconClass : row.iconClass"
                :stroke-width="row.strokeWidth"
            />
            <span
                class="flex-1 text-left text-base font-semibold transition-colors duration-150"
                :class="row.active ? row.activeTextClass : 'text-ink-muted'"
            >
                {{ row.label }}
            </span>
            <UserRatingBadge v-if="row.ratingValue !== null" :rating="row.ratingValue" :positioned="false" :size="40" class="shrink-0" />
        </button>
    </div>
</template>
