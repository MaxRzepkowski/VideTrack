<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import PageHeading from '@/components/PageHeading.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { mediaImage } from '@/lib/images';
import type { SuggestionShelf } from '@/types/suggestions';
import { Head, Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { ChevronRight, Sparkles } from '@lucide/vue';

/**
 * The kinds of suggestion that have something for the user. Each opens its
 * own page; no titles are listed here.
 */
defineProps<{ shelves: SuggestionShelf[] }>();
</script>

<template>
    <AppLayout>
        <Head :title="trans('suggestions.suggestions')" />

        <PageMasthead>
            <div class="max-w-content mx-auto w-full px-4 pt-8 pb-6 md:px-10">
                <PageHeading :title="trans('suggestions.suggestions')" :subtitle="trans('suggestions.titles_you_have_a_reason_to_catch_up_on')">
                    <template #icon><Sparkles class="size-6" /></template>
                </PageHeading>
            </div>
        </PageMasthead>

        <div class="max-w-content mx-auto w-full flex-1 px-4 pt-8 pb-20 md:px-10">
            <EmptyState v-if="shelves.length === 0" :message="trans('suggestions.nothing_to_suggest_yet')" />

            <ul v-else class="m-0 grid list-none grid-cols-1 gap-x-5 gap-y-16 p-0 pt-12 md:grid-cols-2 xl:grid-cols-3">
                <li v-for="shelf in shelves" :key="shelf.key">
                    <Link
                        :href="route('suggestions.show', { shelf: shelf.key })"
                        class="border-line-subtle bg-surface hover:border-brand flex h-full flex-col gap-5 rounded-2xl border p-5 transition-colors duration-150"
                    >
                        <!-- The posters stand in the tile like a fanned hand,
                             turned a little away and rising out of its top
                             edge. The one under the cursor turns to face the
                             reader and comes forward; the tile is still one
                             link, wherever it is clicked. A poster is never wider
                             than its share of a full hand of six (6 × 22.5% less
                             five overlaps of 7%), so a shelf with one or two
                             titles stands as tall as its neighbours. -->
                        <div v-if="shelf.posters.length > 0" class="-mt-12 flex w-full perspective-[900px]" aria-hidden="true">
                            <div
                                v-for="(poster, index) in shelf.posters"
                                :key="poster"
                                class="max-w-[22.5%] min-w-0 flex-1 origin-bottom rotate-y-[-16deg] transition-transform duration-300 ease-out hover:z-50! hover:-translate-y-4 hover:scale-110 hover:rotate-y-0 motion-reduce:transition-none motion-reduce:hover:translate-y-0 motion-reduce:hover:scale-100"
                                :class="index === 0 ? undefined : '-ml-[7%]'"
                                :style="{ zIndex: shelf.posters.length - index }"
                            >
                                <img
                                    :src="mediaImage(poster, 'w185') ?? undefined"
                                    alt=""
                                    width="185"
                                    height="278"
                                    loading="lazy"
                                    class="bg-surface-raised aspect-poster ring-surface shadow-card w-full rounded-xl object-cover ring-4"
                                />
                            </div>
                        </div>
                        <!-- Titles line up across a row; the count sits on the tile's
                             floor however long the rule above it runs. -->
                        <div class="flex flex-1 flex-col">
                            <h2 class="text-ink m-0 text-base font-bold">{{ shelf.title }}</h2>
                            <p class="text-ink-muted m-0 mt-0.5 text-sm font-semibold">{{ shelf.description }}</p>
                            <p class="text-ink-secondary m-0 mt-auto flex items-center gap-1 pt-4 text-sm font-semibold">
                                {{ transChoice('common.count_item', shelf.total, { count: String(shelf.total) }) }}
                                <ChevronRight class="text-ink-muted size-4" aria-hidden="true" />
                            </p>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
