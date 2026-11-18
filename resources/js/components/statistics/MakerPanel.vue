<script setup lang="ts">
import { formatNumber } from '@/lib/format';
import { mediaImage } from '@/lib/images';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { Button } from '@/components/ui/button';
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
                    <img
                        v-if="mediaImage(title.media.posterPath, 'w92')"
                        :src="mediaImage(title.media.posterPath, 'w92') ?? undefined"
                        alt=""
                        width="92"
                        height="138"
                        loading="lazy"
                        class="bg-surface-raised aspect-poster w-11 shrink-0 rounded object-cover"
                    />
                    <span v-else class="bg-surface-raised aspect-poster block w-11 shrink-0 rounded" aria-hidden="true" />
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="text-ink truncate text-base font-semibold group-hover:underline">{{ title.media.title }}</span>
                        <span class="text-ink-muted truncate text-sm">
                            {{
                                title.media.year === null
                                    ? mediaTypeMeta(title.media.type).label()
                                    : trans('statistics.type_and_year', {
                                          type: mediaTypeMeta(title.media.type).label(),
                                          year: String(title.media.year),
                                      })
                            }}
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
