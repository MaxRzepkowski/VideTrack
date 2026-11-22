<script setup lang="ts">
import PosterThumb from '@/components/statistics/PosterThumb.vue';
import { formatNumber } from '@/lib/format';
import { Button } from '@/components/ui/button';
import { typeAndYear } from '@/lib/statistics';
import { creatorHref, mediaUrl } from '@/lib/urls';
import type { MakerTally, RatedTitle } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

/**
 * Beside the bubbles: the network or studio clicked, a way to its page, and
 * the best rated of its titles in the period, each by the user's rating or, where the user gave
 * none, everyone else's.
 */
const props = defineProps<{
    maker: MakerTally;
    titles: RatedTitle[];
    /** How many titles, said the way the card says it. */
    count: string;
}>();

const page = computed(() => creatorHref(props.maker.creator));

const best = computed(() => props.maker.best.map((id) => props.titles[id]).filter((title): title is RatedTitle => title !== undefined));

const rating = (title: RatedTitle): { value: string; whose: string } | null => {
    if (title.rating !== null) {
        return { value: formatNumber(title.rating), whose: trans('statistics.rating_yours') };
    }

    return title.media.communityRating === null
        ? null
        : { value: formatNumber(title.media.communityRating, 1), whose: trans('statistics.rating_others') };
};
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4" aria-live="polite">
        <div class="flex flex-col gap-1">
            <h4 class="text-ink m-0 truncate text-base font-semibold">{{ trans('statistics.maker_best', { name: maker.creator.name }) }}</h4>
            <p class="text-ink-muted m-0 text-sm">{{ count }}</p>
        </div>

        <Button v-if="page" as-child variant="secondary" size="sm" class="self-start">
            <Link :href="page">{{ trans(maker.creator.kind === 'network' ? 'statistics.maker_open_network' : 'statistics.maker_open_studio') }}</Link>
        </Button>

        <ol class="m-0 flex list-none flex-col gap-3 p-0">
            <li v-for="title in best" :key="`${title.media.type}-${title.media.ref}`">
                <Link
                    :href="mediaUrl(title.media)"
                    class="group focus-visible:ring-brand flex items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:outline-hidden"
                >
                    <PosterThumb :path="title.media.posterPath" size="w92" :width="92" :height="138" class="aspect-poster w-11 rounded" />
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span v-tooltip.truncated="title.media.title" class="text-ink truncate text-base font-semibold group-hover:underline">{{
                            title.media.title
                        }}</span>
                        <span class="text-ink-muted truncate text-sm">
                            {{ typeAndYear(title.media.type, title.media.year) }}
                        </span>
                    </span>
                    <span v-if="rating(title)" class="flex shrink-0 flex-col items-end">
                        <span class="text-ink text-lg font-semibold tabular-nums">{{ rating(title)?.value }}</span>
                        <span class="text-ink-muted text-sm">{{ rating(title)?.whose }}</span>
                    </span>
                </Link>
            </li>
        </ol>
    </div>
</template>
