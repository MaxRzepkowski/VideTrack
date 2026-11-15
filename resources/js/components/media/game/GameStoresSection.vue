<script setup lang="ts">
import SectionHeading from '@/components/SectionHeading.vue';
import { glyphFor } from '@/lib/serviceGlyphs';
import { SELECT_CLASS_ON_CARD } from '@/lib/ui';
import { safeUrl } from '@/lib/url';
import type { GameStore, StoreLink } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';
import { ShoppingBag } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// Where a game can be had, as a film's "Where to watch" card says where it
// streams: each store opens the game's own page there; a subscription
// (Game Pass) is its own group, the way streaming is apart from buying.
const props = defineProps<{ stores: StoreLink[] }>();

// Brand names are not translated.
const STORE_NAMES: Record<GameStore, string> = {
    steam: 'Steam',
    gog: 'GOG',
    epicgames: 'Epic Games Store',
    playstation: 'PlayStation Store',
    xbox: 'Xbox',
    nintendo: 'Nintendo eShop',
    itch: 'itch.io',
    appstore: 'App Store',
    googleplay: 'Google Play',
    gamepass: 'Game Pass',
    utomik: 'Utomik',
};

// Each store's mark on its own colour (tokens in app.css); Game Pass wears
// Xbox's, a store without a colour of its own a neutral one.
const STORE_TINTS: Record<GameStore, string> = {
    steam: 'bg-store-steam',
    gog: 'bg-store-gog',
    epicgames: 'bg-store-epicgames',
    playstation: 'bg-store-playstation',
    xbox: 'bg-store-xbox',
    nintendo: 'bg-store-nintendo',
    itch: 'bg-store-itch',
    appstore: 'bg-store-appstore',
    googleplay: 'bg-store-googleplay',
    gamepass: 'bg-store-xbox',
    utomik: 'bg-surface-overlay',
};

type Entry = StoreLink & { url: string; name: string; glyph: string | null; tint: string };

const links = computed(() =>
    props.stores.flatMap((link): Entry[] => {
        const url = safeUrl(link.url);

        return url === null
            ? []
            : [
                  {
                      ...link,
                      url,
                      name: STORE_NAMES[link.store],
                      glyph: glyphFor(link.store),
                      tint: STORE_TINTS[link.store],
                  },
              ];
    }),
);

const groups = computed(() =>
    [
        { key: 'buy', label: trans('media.buy'), entries: links.value.filter((link) => !link.subscription) },
        { key: 'subscription', label: trans('media.in_subscription'), entries: links.value.filter((link) => link.subscription) },
    ].filter((group) => group.entries.length > 0),
);

const selectedGroup = ref<string | null>(null);
const currentGroup = computed(() => groups.value.find((group) => group.key === selectedGroup.value) ?? groups.value[0] ?? null);
</script>

<template>
    <section v-if="currentGroup">
        <div class="border-line-subtle bg-surface rounded-2xl border p-5">
            <SectionHeading :title="trans('media.where_to_buy')">
                <select
                    v-if="groups.length > 1"
                    :value="currentGroup.key"
                    :class="SELECT_CLASS_ON_CARD"
                    :aria-label="trans('media.where_to_buy')"
                    @change="selectedGroup = ($event.target as HTMLSelectElement).value"
                >
                    <option v-for="group in groups" :key="group.key" :value="group.key">{{ group.label }}</option>
                </select>
            </SectionHeading>

            <div class="flex flex-wrap gap-2.5">
                <a
                    v-for="entry in currentGroup.entries"
                    :key="entry.store"
                    :href="entry.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="border-line bg-surface-raised text-ink hover:border-brand flex items-center gap-3 rounded-xl border py-1.5 pr-4 pl-1.5 text-base font-semibold transition-colors duration-150"
                >
                    <span class="text-on-accent flex size-9 shrink-0 items-center justify-center rounded-lg" :class="entry.tint">
                        <svg v-if="entry.glyph" viewBox="0 0 24 24" fill="currentColor" class="size-5" aria-hidden="true">
                            <path :d="entry.glyph" />
                        </svg>
                        <ShoppingBag v-else class="size-5" :stroke-width="2" aria-hidden="true" />
                    </span>
                    {{ entry.name }}
                </a>
            </div>
        </div>
    </section>
</template>
