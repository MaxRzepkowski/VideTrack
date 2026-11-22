<script setup lang="ts">
import ErrorBanner from '@/components/ErrorBanner.vue';
import CollectionCard from '@/components/media/CollectionCard.vue';
import CreatorCard from '@/components/media/CreatorCard.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaCardSkeleton from '@/components/media/MediaCardSkeleton.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { creatorOf, isCollection } from '@/lib/creators';
import { inRegistryOrder, kindGlyph, kindsLabel } from '@/lib/mediaTypes';
import type { MediaSummary } from '@/types/media';
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { Search as SearchIcon, X } from '@lucide/vue';
import { onMounted, ref, useTemplateRef, watch } from 'vue';

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
const field = useTemplateRef<HTMLInputElement>('field');
// The scope picker queries one provider at a time. It has no "All": searching
// every module at once would stretch the wait for every keystroke.
// Only shown when there is a choice to make.
const activeScope = ref(props.scope);

// A scope's glyphs, one per type it covers, in the registry's order: "Shows and
// movies" wears both.
const glyphs = (types: string[]) => inRegistryOrder(types).map((type) => ({ type, ...kindGlyph(type) }));
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

// `autofocus` only counts on a full page load; the menu reaches this page by an
// Inertia visit, so the box takes the cursor itself.
onMounted(() => field.value?.focus());

function clear(): void {
    input.value = '';
    field.value?.focus();
}

// Esc first undoes the words, then lets the keyboard out of the box (to the
// scope buttons and the results), as the box takes the cursor on its own.
function onEscape(): void {
    if (input.value === '') {
        field.value?.blur();
    } else {
        input.value = '';
    }
}

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

            <div class="mx-auto max-w-220">
                <!-- The purple ring and the Esc hint only while the box is empty:
                     once there are words, the results below are what to look at. -->
                <div class="relative">
                    <input
                        ref="field"
                        v-model="input"
                        type="text"
                        :placeholder="trans('media.search_titles_and_people')"
                        :aria-label="trans('media.search_titles_and_people')"
                        class="peer border-line-strong bg-surface text-ink placeholder:text-ink-faint placeholder-shown:focus:border-brand-soft placeholder-shown:focus:ring-brand-soft h-13 w-full rounded-xl border pr-14 pl-13 text-xl font-medium outline-none placeholder:font-normal placeholder-shown:focus:ring-1 placeholder-shown:focus:ring-inset md:h-15 md:pl-14"
                        @keydown.esc.prevent="onEscape"
                    />
                    <SearchIcon
                        class="text-ink-secondary peer-placeholder-shown:text-ink-muted peer-placeholder-shown:peer-focus:text-brand-soft pointer-events-none absolute top-1/2 left-4.5 size-5.5 -translate-y-1/2 md:left-5"
                        aria-hidden="true"
                    />
                    <kbd
                        class="bg-surface-raised text-ink-muted pointer-events-none absolute top-1/2 right-2.5 hidden -translate-y-1/2 rounded-md px-2 py-1 font-sans text-sm font-medium md:peer-placeholder-shown:peer-focus:block"
                        aria-hidden="true"
                        >{{ trans('media.search_escape_key') }}</kbd
                    >
                    <button
                        v-if="input !== ''"
                        type="button"
                        :aria-label="trans('media.clear_search')"
                        class="bg-surface-raised text-ink-muted hover:text-ink absolute top-1/2 right-2.5 flex size-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-lg transition-colors duration-150"
                        @click="clear"
                    >
                        <X class="size-4.5" />
                    </button>
                </div>

                <div
                    v-if="scopes.length > 1"
                    class="scroll-none -mx-4 mt-3 flex gap-2 overflow-x-auto px-4 md:mx-0 md:px-0"
                    role="group"
                    :aria-label="trans('media.search_in')"
                >
                    <button
                        v-for="option in scopes"
                        :key="option.key"
                        type="button"
                        :aria-pressed="activeScope === option.key"
                        class="flex h-10 shrink-0 cursor-pointer items-center gap-2 rounded-[10px] pr-3.5 pl-3 text-[15px] leading-5 transition-colors duration-150"
                        :class="
                            activeScope === option.key
                                ? 'bg-surface-overlay text-ink font-semibold'
                                : 'bg-surface text-ink-secondary hover:bg-surface-raised hover:text-ink font-medium'
                        "
                        @click="setScope(option.key)"
                    >
                        <span class="flex gap-0.5" aria-hidden="true">
                            <component
                                :is="glyph.icon"
                                v-for="glyph in glyphs(option.types)"
                                :key="glyph.type"
                                class="size-4.5"
                                :class="activeScope === option.key ? '' : 'text-ink-muted'"
                                :style="activeScope === option.key ? { color: glyph.color } : undefined"
                            />
                        </span>
                        {{ kindsLabel(option.types) }}
                    </button>
                </div>
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
