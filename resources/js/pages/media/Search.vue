<script setup lang="ts">
import ErrorBanner from '@/components/ErrorBanner.vue';
import CollectionCard from '@/components/media/CollectionCard.vue';
import CreatorCard from '@/components/media/CreatorCard.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaCardSkeleton from '@/components/media/MediaCardSkeleton.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { creatorOf, isCollection } from '@/lib/creators';
import { kindsLabel } from '@/lib/mediaTypes';
import { SELECT_CLASS } from '@/lib/ui';
import type { MediaSummary } from '@/types/media';
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { Search as SearchIcon } from '@lucide/vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    query: string;
    /** Null on an instance with nothing to search. */
    scope: string | null;
    scopes: { key: string; types: string[] }[];
    results: MediaSummary[] | null;
    /** The scope's provider could not answer: no results is not "nothing found". */
    unavailable: boolean;
}>();

const input = ref(props.query);
// The scope picker queries one provider at a time. It has no "All": searching
// every module at once would stretch the wait for every keystroke.
// Only shown when there is a choice to make.
const activeScope = ref(props.scope);
const searching = ref(false);
const failed = ref(false);

function visit(query: string): void {
    router.get(
        route('search'),
        {
            ...(query === '' ? {} : { q: query }),
            ...(activeScope.value === null ? {} : { scope: activeScope.value }),
        },
        {
            // Entity cards read their state from props of their own: left out
            // of this list, a person found by typing comes back claiming it is
            // on no list and unfollowed, however loudly the bell is ringing.
            only: ['query', 'scope', 'results', 'unavailable', 'userStates', 'creatorStates'],
            preserveState: true,
            preserveScroll: true,
            preserveUrl: false,
            replace: true,
            onStart: () => {
                searching.value = true;
                failed.value = false;
            },
            onFinish: () => (searching.value = false),
            onError: () => (failed.value = true),
        },
    );
}

// Inertia cancels the previous visit when a new one starts, so together with
// the debounce stale responses never overwrite fresh ones.
const search = useDebounceFn(visit, 400);

watch(input, (value) => search(value.trim()));

function setScope(scope: string): void {
    if (activeScope.value === scope) {
        return;
    }

    activeScope.value = scope;

    // Switching the scope re-runs the current query immediately — there is
    // no keystroke to wait for.
    if (input.value.trim() !== '') {
        visit(input.value.trim());
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.search')" />

        <div class="max-w-content mx-auto w-full flex-1 px-4 py-8 md:px-10">
            <!-- The box is the page; the heading is there for screen readers. -->
            <h1 class="sr-only">{{ trans('common.search') }}</h1>

            <div class="max-w-dialog-lg relative mx-auto mb-3">
                <SearchIcon class="text-ink-faint pointer-events-none absolute top-1/2 left-5 size-5 -translate-y-1/2" />
                <input
                    v-model="input"
                    type="text"
                    autofocus
                    :placeholder="trans('common.search_movies_and_tv_shows')"
                    :aria-label="trans('common.search_movies_and_tv_shows')"
                    class="border-line bg-surface text-ink placeholder:text-ink-faint focus:border-brand w-full rounded-full border py-4 pr-5 pl-14 text-lg transition-colors duration-150 outline-none"
                />
            </div>

            <div v-if="scopes.length > 1" class="max-w-dialog-lg mx-auto mb-3 flex justify-center">
                <select
                    :value="activeScope"
                    :class="SELECT_CLASS"
                    :aria-label="trans('media.search_in')"
                    @change="setScope(($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="option in scopes" :key="option.key" :value="option.key">{{ kindsLabel(option.types) }}</option>
                </select>
            </div>

            <MediaGrid v-if="searching" class="mt-8">
                <MediaCardSkeleton v-for="n in 10" :key="n" />
            </MediaGrid>

            <ErrorBanner v-else-if="failed || unavailable" :message="trans('media.search_is_unavailable_right_now_please')" />

            <p v-else-if="results === null" class="text-ink-faint py-16 text-center text-lg">
                {{ trans('media.start_typing_to_search_for_movies') }}
            </p>

            <p v-else-if="results.length === 0" class="text-ink-faint py-16 text-center text-lg">
                {{ trans('common.no_results_for_query', { query }) }}
            </p>

            <MediaGrid v-else class="mt-8">
                <template v-for="media in results" :key="`${media.type}-${media.ref}`">
                    <CreatorCard v-if="creatorOf(media)" :media="media" />
                    <CollectionCard v-else-if="isCollection(media)" :collection="media" />
                    <MediaCard v-else :media="media" />
                </template>
            </MediaGrid>
        </div>
    </AppLayout>
</template>
