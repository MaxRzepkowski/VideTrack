<script setup lang="ts">
import { brandFor } from '@/lib/serviceGlyphs';
import { safeUrl } from '@/lib/url';
import { trans } from 'laravel-vue-i18n';
import { ExternalLink, Globe } from '@lucide/vue';
import { computed } from 'vue';

// One dedicated icon per external service, from the app's one map of brand
// marks (`lib/serviceGlyphs`); homepage uses a globe and anything with no mark
// there (provider-only sites) falls back to a generic "external link" mark
// with the name as a tooltip.
const props = defineProps<{
    links: Record<string, string>;
}>();

// Labels for the keys without a brand glyph (homepage → globe, the rest → the
// generic external-link mark). Brand names are not translated; the homepage is.
const FALLBACK_LABELS: Record<string, () => string> = {
    homepage: () => trans('media.official_website'),
    comicvine: () => 'ComicVine',
    hardcover: () => 'Hardcover',
};

const entries = computed(() =>
    Object.entries(props.links)
        .map(([kind, url]) => ({ kind, url: safeUrl(url) }))
        .filter((entry): entry is { kind: string; url: string } => entry.url !== null)
        .map(({ kind, url }) => {
            const icon = brandFor(kind);

            return {
                kind,
                url,
                label: icon?.label ?? FALLBACK_LABELS[kind]?.() ?? kind,
                path: icon?.path ?? null,
                homepage: kind === 'homepage',
            };
        }),
);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <a
            v-for="entry in entries"
            :key="entry.kind"
            v-tooltip="entry.label"
            :href="entry.url"
            target="_blank"
            rel="noopener noreferrer"
            class="border-line bg-surface-raised text-ink-secondary hover:border-brand hover:text-ink flex h-9 w-9 items-center justify-center rounded-lg border transition-colors duration-150"
        >
            <Globe v-if="entry.homepage" class="size-4.5" :stroke-width="2" />
            <svg v-else-if="entry.path" viewBox="0 0 24 24" fill="currentColor" class="size-4.25" aria-hidden="true">
                <path :d="entry.path" />
            </svg>
            <ExternalLink v-else class="size-4.5" :stroke-width="2" />
        </a>
    </div>
</template>
