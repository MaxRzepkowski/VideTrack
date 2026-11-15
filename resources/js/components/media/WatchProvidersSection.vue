<script setup lang="ts">
import SectionHeading from '@/components/SectionHeading.vue';
import { countryName } from '@/lib/format';
import { mediaImage } from '@/lib/images';
import { SELECT_CLASS_ON_CARD } from '@/lib/ui';
import { safeUrl } from '@/lib/url';
import type { WatchAvailability } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

const props = defineProps<{
    providers: WatchAvailability | null;
}>();

// Allow-list the JustWatch deep link to http(s) before it becomes an href.
const safeLink = computed(() => safeUrl(props.providers?.link));

const groups = computed(() =>
    [
        { key: 'stream', label: trans('media.stream'), entries: props.providers?.stream ?? [] },
        { key: 'free', label: trans('media.stream_free'), entries: props.providers?.free ?? [] },
        { key: 'rent', label: trans('media.rent'), entries: props.providers?.rent ?? [] },
        { key: 'buy', label: trans('media.buy'), entries: props.providers?.buy ?? [] },
    ].filter((group) => group.entries.length > 0),
);

const selectedGroup = ref<string | null>(null);
const currentGroup = computed(() => groups.value.find((g) => g.key === selectedGroup.value) ?? groups.value[0] ?? null);

// This block is entirely about the viewer's own country, unlike the worldwide
// Releases block above it — so it says which country, rather than leaving an
// empty offer list looking like missing data.
const regionName = computed<string | null>(() => (props.providers?.region ? countryName(props.providers.region) : null));
</script>

<template>
    <section v-if="providers && currentGroup">
        <div class="border-line-subtle bg-surface rounded-2xl border p-5">
            <SectionHeading :title="trans('media.where_to_watch')">
                <select
                    :value="currentGroup.key"
                    :class="SELECT_CLASS_ON_CARD"
                    :aria-label="trans('media.where_to_watch')"
                    @change="selectedGroup = ($event.target as HTMLSelectElement).value"
                >
                    <option v-for="group in groups" :key="group.key" :value="group.key">{{ group.label }}</option>
                </select>
            </SectionHeading>

            <p v-if="regionName" class="text-ink-muted m-0 mb-3 text-sm">{{ trans('media.in_your_region_region', { region: regionName }) }}</p>

            <!-- No per-service deep links in the data (TMDB terms) — every
                 logo goes to the title's TMDB watch page instead. -->
            <div class="flex flex-wrap gap-2.5">
                <component
                    :is="safeLink ? 'a' : 'div'"
                    v-for="entry in currentGroup.entries"
                    :key="entry.name"
                    v-tooltip="entry.name"
                    :href="safeLink ?? undefined"
                    :target="safeLink ? '_blank' : undefined"
                    :rel="safeLink ? 'noopener noreferrer' : undefined"
                    class="border-line block overflow-hidden rounded-xl border transition-colors duration-150"
                    :class="safeLink ? 'hover:border-brand' : ''"
                >
                    <img
                        v-if="entry.logoPath"
                        :src="mediaImage(entry.logoPath, 'w92') ?? undefined"
                        :alt="entry.name"
                        width="92"
                        height="92"
                        loading="lazy"
                        class="size-12 object-cover"
                    />
                    <div
                        v-else
                        class="bg-surface-raised text-ink-muted flex size-12 items-center justify-center px-1 text-center text-sm leading-tight font-semibold"
                    >
                        {{ entry.name }}
                    </div>
                </component>
            </div>

            <!-- Attribution required by the TMDB API terms wherever
                 watch-provider data is rendered. -->
            <p class="border-line-subtle text-ink-faint m-0 mt-4 border-t pt-3 text-sm">
                {{ trans('media.streaming_data_powered_by_justwatch') }}
            </p>
        </div>
    </section>
</template>
