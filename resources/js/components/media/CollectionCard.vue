<script setup lang="ts">
import CardCaption from '@/components/media/CardCaption.vue';
import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { POSTER_CARD_SIZES } from '@/lib/ui';
import { mediaUrl } from '@/lib/urls';
import type { MediaSummary } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Layers } from '@lucide/vue';
import { computed } from 'vue';

// A summary of type `collection`: a provider collection (film saga, book or
// game series) as its materialized list. Collections carry no watched /
// rating state and no list membership — the card is a plain link, like a
// person card stripped of its action bar. Its own cover is a backdrop, so the
// poster grid borrows the first part's poster instead.
const props = defineProps<{ collection: MediaSummary }>();

const poster = computed(() => mediaImage(props.collection.posterPath, 'w342'));
const href = computed(() => mediaUrl(props.collection));

const subtitle = computed(() => {
    const count = props.collection.extra.itemCount;

    if (typeof count !== 'number') {
        return undefined;
    }

    return transChoice('common.count_item', count, { count: String(count) });
});
</script>

<template>
    <div class="group flex flex-col">
        <div
            class="border-line-subtle bg-surface group-hover:border-brand xs:rounded-2xl flex flex-col overflow-hidden rounded-xl border transition-colors duration-150"
        >
            <Link :href="href" class="block">
                <div class="bg-surface-raised aspect-poster relative w-full overflow-hidden">
                    <img
                        v-if="poster"
                        :src="poster"
                        :srcset="mediaImageSrcSet(collection.posterPath, [185, 342])"
                        :sizes="POSTER_CARD_SIZES"
                        :alt="collection.title"
                        width="342"
                        height="513"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="from-surface-raised to-line flex h-full w-full items-center justify-center bg-gradient-to-br">
                        <Layers class="text-ink-muted size-10" />
                    </div>
                    <div
                        class="bg-brand xs:top-3 xs:right-3 xs:gap-1.5 xs:px-2.5 xs:py-1 text-on-accent absolute top-2 right-2 flex items-center gap-1 rounded-md px-1.5 py-0.5 text-sm font-semibold"
                    >
                        <Layers class="xs:size-3.5 size-3" :stroke-width="2.4" />
                        {{ trans('media.collection') }}
                    </div>
                </div>
            </Link>
            <CardCaption :title="collection.title" :subtitle="subtitle" :href="href" :subtitle-href="href" />
        </div>
    </div>
</template>
