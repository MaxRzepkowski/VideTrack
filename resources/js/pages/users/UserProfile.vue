<script setup lang="ts">
import PageMasthead from '@/components/PageMasthead.vue';
import PageSection from '@/components/PageSection.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import StatTile from '@/components/dashboard/StatTile.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import UserHeader from '@/components/users/UserHeader.vue';
import { useReloadAfterWrites } from '@/composables/useReloadAfterWrites';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCalendarDate } from '@/lib/format';
import { inRegistryOrder } from '@/lib/mediaTypes';
import type { MediaSummary, ProfileData, WatchStats } from '@/types/media';
import { Head, Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { ChartNoAxesColumnIncreasing, ChevronRight, Heart } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    profile: ProfileData;
    isOwner: boolean;
    favoritesCount: number;
    favorites: MediaSummary[];
    stats: Record<string, WatchStats>;
}>();

// The favourites shelf and the stats move with a write.
useReloadAfterWrites(['favorites', 'favoritesCount', 'stats']);

// Computed, not a constant: Inertia reuses this component when you walk from
// one profile to the next, and setup does not run again.
const favoritesHref = computed(() => route('users.favorites', { user: props.profile.username }));

const favoritesLabel = computed(() => transChoice('users.count_title', props.favoritesCount, { count: String(props.favoritesCount) }));

// The dashboard's tile set, counted for whoever this profile belongs to:
// the two core types always, the optional modules only where this instance
// has them configured. Layout rule from the dashboard as well — columns are
// half the tiles, so 5–6 types wrap to 3×2 and 3–4 to 2×2.
const statKinds = computed(() => inRegistryOrder(Object.keys(props.stats)));
const statColumns = computed(() => (Math.ceil(statKinds.value.length / 2) >= 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-2'));
</script>

<template>
    <AppLayout>
        <Head :title="profile.name" />

        <!-- The header states the name in its pill and the lit tab names the
             panel, so nothing visible has to repeat either. What they cannot
             carry — a heading for a screen reader — stays, out of sight. -->
        <h1 class="sr-only">{{ profile.name }}</h1>

        <PageMasthead class="pb-4">
            <UserHeader :profile="profile" :is-owner="isOwner" active="show" />
        </PageMasthead>

        <!-- What the header leaves out: the bio and since when the account
             exists. It wears the control strip's surface and sits
             where that strip sits on every other panel, so the profile keeps
             their rhythm instead of reading as a taller header. -->
        <div v-if="profile.bio || profile.memberSince" class="border-line-faint bg-surface-toolbar/90 border-y backdrop-blur-md">
            <!-- `min-h-10` is the height of a control on that strip, so a
                 profile with nothing but a join date makes a band exactly as
                 tall as the filters one. A bio grows it, as any wrapped row
                 of controls would. -->
            <div class="max-w-content mx-auto flex w-full flex-col justify-center px-4 py-4 md:px-10">
                <div class="flex min-h-10 flex-col justify-center">
                    <p v-if="profile.bio" class="text-ink-secondary max-w-reading-lg m-0 text-base whitespace-pre-line">{{ profile.bio }}</p>
                    <p v-if="profile.memberSince" class="text-ink-faint m-0 text-base font-semibold" :class="profile.bio ? 'mt-3' : ''">
                        {{ trans('users.member_since_date', { date: formatCalendarDate(profile.memberSince) ?? '' }) }}
                    </p>
                </div>
            </div>
        </div>

        <!-- The same tiles the dashboard opens with, about this person. Each
             one is a shortcut into their history, filtered to its type. -->
        <PageSection :class="favorites.length === 0 ? 'pb-20' : ''">
            <SectionHeading :title="trans('users.stats')">
                <template #icon><ChartNoAxesColumnIncreasing class="size-6" /></template>
            </SectionHeading>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" :class="statColumns">
                <StatTile v-for="kind in statKinds" :key="kind" :kind="kind" :stats="stats[kind]" :username="profile.username" />
            </div>
        </PageSection>

        <!-- Favorites. The shelf wears the same card as every other shelf in
             the app; the actions come off it because this page never asked the
             server for your marks — the bar would report every title as
             untouched. The tab behind "See all" has them. -->
        <PageSection v-if="favorites.length > 0" tone="subtle" class="pb-20">
            <SectionHeading :title="trans('common.favorites')" :subtitle="favoritesLabel">
                <template #icon><Heart class="size-6" /></template>
                <Link
                    :href="favoritesHref"
                    class="text-brand-soft hover:text-brand hidden items-center gap-1.5 text-base font-semibold transition-colors sm:inline-flex"
                >
                    {{ trans('users.see_all') }}
                    <ChevronRight class="size-4" :stroke-width="2.5" aria-hidden="true" />
                </Link>
            </SectionHeading>
            <MediaGrid>
                <MediaCard v-for="media in favorites" :key="`${media.type}-${media.ref}`" :media="media" :with-actions="false" />
            </MediaGrid>
            <Link
                :href="favoritesHref"
                class="text-brand-soft hover:text-brand mt-5 ml-auto flex w-fit items-center gap-1.5 text-base font-semibold transition-colors sm:hidden"
            >
                {{ trans('users.see_all') }}
                <ChevronRight class="size-4" :stroke-width="2.5" aria-hidden="true" />
            </Link>
        </PageSection>

        <!-- The page must still fill the viewport when the shelf is empty. -->
        <div class="flex-1"></div>
    </AppLayout>
</template>
