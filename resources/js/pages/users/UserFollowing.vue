<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import CollectionCard from '@/components/media/CollectionCard.vue';
import CreatorCard from '@/components/media/CreatorCard.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import Pagination from '@/components/Pagination.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import UserHeader from '@/components/users/UserHeader.vue';
import { useCreatorActions } from '@/composables/useCreatorActions';
import { useMediaActions } from '@/composables/useMediaActions';
import { useReloadAfterWrites } from '@/composables/useReloadAfterWrites';
import AppLayout from '@/layouts/AppLayout.vue';
import { creatorOf, type CreatorStatesMap, isCollection } from '@/lib/creators';
import { kindPlural, MEDIA_TYPE_META } from '@/lib/mediaTypes';
import { SELECT_CLASS } from '@/lib/ui';
import type { FollowEntry, TypeCount } from '@/types/generated';
import type { ProfileData, UserStatesMap } from '@/types/media';
import type { PageInfo } from '@/types/pagination';
import { Head, router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { X } from '@lucide/vue';
import { computed } from 'vue';

// MediaCard and the creator cards read userStates and creatorStates from the
// page props themselves — declaring them here is what puts them there.
const props = defineProps<{
    profile: ProfileData;
    isOwner: boolean;
    entries: FollowEntry[];
    types: TypeCount[];
    activeType: string | null;
    kinds: { kind: FollowEntry['kind']; count: number }[];
    activeKind: FollowEntry['kind'] | null;
    pagination: PageInfo;
    userStates: UserStatesMap;
    creatorStates: CreatorStatesMap;
}>();

// A row leaves or changes with its bell.
useReloadAfterWrites(['entries', 'types', 'kinds', 'pagination', 'userStates']);

const { setFollow } = useMediaActions();

// The followed list is not only media — people, companies and collections
// need their own words; every real media type keeps the filter's own label.
const typeFilterOptions = computed(() =>
    props.types.map((option) => (MEDIA_TYPE_META[option.type] ? option : { ...option, label: kindPlural(option.type) })),
);

const KIND_LABELS: Record<FollowEntry['kind'], () => string> = {
    person: () => kindPlural('person'),
    company: () => kindPlural('company'),
    collection: () => kindPlural('collection'),
    manual: () => trans('common.followed_manually'),
    via: () => trans('users.via_followed_people_or_companies'),
    muted: () => trans('common.muted'),
};

const kindLabel = (kind: FollowEntry['kind']): string => KIND_LABELS[kind]();

function visit(params: { type?: string | null; kind?: string | null; page?: number }): void {
    const type = params.type === undefined ? props.activeType : params.type;
    const kind = params.kind === undefined ? props.activeKind : params.kind;
    const page = params.page ?? 1;

    router.get(
        route('users.following', { user: props.profile.username }),
        { ...(type ? { type } : {}), ...(kind ? { kind } : {}), ...(page > 1 ? { page } : {}) },
        { preserveScroll: false, preserveState: false },
    );
}

// Both filters live in the query string, so a pick is a visit, not local
// state — the setters navigate and the props stay the single source.
const activeFilterCount = computed(() => [props.activeType, props.activeKind].filter((value) => value !== null).length);

const typeFilter = computed<string | null>({ get: () => props.activeType, set: (type) => visit({ type, page: 1 }) });

const kindFilter = computed<string | null>({ get: () => props.activeKind, set: (kind) => visit({ kind, page: 1 }) });

/** The under-card action per kind: label + what clicking it does. */
function actionLabel(entry: FollowEntry): string {
    switch (entry.kind) {
        case 'via':
            return trans('users.mute');
        case 'muted':
            return trans('users.unmute');
        default:
            return trans('common.stop_following');
    }
}

const { follow, followCollection } = useCreatorActions();

function act(entry: FollowEntry): void {
    if (entry.kind === 'person') {
        void follow({ kind: 'person', provider: entry.media.provider, ref: entry.media.ref }, false);

        return;
    }

    // Both "stop following" and "unmute" clear a collection's row — nothing
    // sponsors a collection bell, so there is no auto-state to fall back to.
    if (isCollection(entry.media)) {
        void followCollection(entry.media.provider, entry.media.ref, null, []);

        return;
    }

    if (entry.kind === 'company') {
        void follow(
            { kind: entry.media.extra.kind === 'network' ? 'network' : 'company', provider: entry.media.provider, ref: entry.media.ref },
            false,
        );

        return;
    }

    // A person-sourced row is MUTED instead of deleted — a plain removal
    // would come back with the next morning's sweep. Manual follows and
    // mutes clear back to the matrix.
    setFollow(entry.media, entry.kind === 'via' ? 'mute' : null);
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('users.name_following', { name: profile.name })" />

        <!-- The lit tab is this page's title, so no line repeats it in words.
             What it cannot carry — a heading for a screen reader — stays, out
             of sight; the count in the strip below says the rest. -->
        <h1 class="sr-only">{{ trans('common.following') }}</h1>

        <PageMasthead class="pb-4">
            <UserHeader :profile="profile" :is-owner="isOwner" active="following" identity="link" />
        </PageMasthead>

        <PageToolbar
            variant="band"
            :count="transChoice('common.count_item', pagination.total)"
            :filter-count="activeFilterCount"
            @clear-filters="visit({ type: null, kind: null, page: 1 })"
        >
            <!-- Both fields stand whatever the set holds: a panel that
                 empties itself leaves a button opening onto nothing, and a
                 control that comes and goes is one the user cannot learn. -->
            <template #filters>
                <label class="flex flex-col gap-1.5">
                    <span class="text-ink-muted text-base font-bold">{{ trans('users.kind') }}</span>
                    <select v-model="kindFilter" data-toolbar-control :class="SELECT_CLASS">
                        <option :value="null">{{ trans('users.all_kinds') }}</option>
                        <option v-for="option in kinds" :key="option.kind" :value="option.kind">
                            {{ trans('common.label_with_count', { label: kindLabel(option.kind), count: String(option.count) }) }}
                        </option>
                    </select>
                </label>

                <TypeFilter v-model="typeFilter" :options="typeFilterOptions" :labelled="true" />
            </template>
        </PageToolbar>

        <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
            <MediaGrid v-if="entries.length">
                <div
                    v-for="entry in entries"
                    :key="`${entry.kind}:${entry.media.type}:${entry.media.provider}:${entry.media.ref}`"
                    class="flex flex-col"
                >
                    <div :class="{ 'opacity-60': entry.kind === 'muted' }">
                        <CreatorCard v-if="creatorOf(entry.media)" :media="entry.media" />
                        <CollectionCard v-else-if="isCollection(entry.media)" :collection="entry.media" />
                        <MediaCard v-else :media="entry.media" :subtitle="entry.personName ?? undefined" />
                    </div>
                    <!-- Under-card text action, the Activity-page convention. -->
                    <button
                        type="button"
                        class="text-ink-faint hover:text-danger mx-auto mt-2 flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-0.5 text-sm font-semibold transition-colors duration-150"
                        @click="act(entry)"
                    >
                        <X class="size-4" :stroke-width="2.2" />
                        {{ actionLabel(entry) }}
                    </button>
                </div>
            </MediaGrid>

            <EmptyState v-else :message="trans('users.nothing_followed_yet_use_the_bell')" />

            <div v-if="pagination.totalPages > 1" class="mt-8">
                <Pagination :current-page="pagination.currentPage" :total-pages="pagination.totalPages" @goto="(page: number) => visit({ page })" />
            </div>
        </div>
    </AppLayout>
</template>
