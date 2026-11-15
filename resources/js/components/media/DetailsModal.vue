<script setup lang="ts">
import ExternalLinks from '@/components/media/ExternalLinks.vue';
import RefreshButton from '@/components/media/RefreshButton.vue';
import { Dialog, DialogContent, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { mediaImage } from '@/lib/images';
import { creatorHref } from '@/lib/urls';
import type { CreatorLink, DetailRow, KeywordData } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

/** A detail row whose value is a list of clickable creators (game dev / publisher). */
interface CreatorRow {
    label: string;
    creators: CreatorLink[];
}

// A network / studio chip links to its own page when the provider gives an id;
// otherwise it is a plain label. `kind` is forced per row rather than read off
// the link: a stored row may carry no `kind`, and the
// prop the row arrived in already says which page it belongs to.
function networkHref(network: CreatorLink): string | null {
    return creatorHref({ ...network, kind: 'network' });
}

function studioHref(studio: CreatorLink): string | null {
    return creatorHref({ ...studio, kind: 'company' });
}

/** Refresh action shown at the bottom of the modal (endpoint + optional body). */
interface RefreshTarget {
    url: string;
    data?: Record<string, string>;
}

const props = defineProps<{
    title: string;
    rows: DetailRow[];
    keywords?: KeywordData[];
    keywordType?: string;
    links?: Record<string, string>;
    networks?: CreatorLink[];
    studios?: CreatorLink[];
    creatorRows?: CreatorRow[];
    refresh?: RefreshTarget;
}>();

const linkedCreatorRows = computed(() =>
    (props.creatorRows ?? []).map((row) => ({
        label: row.label,
        creators: row.creators.map((creator) => ({ name: creator.name, href: creatorHref(creator) })),
    })),
);

// Controlled so a successful refresh can close the dialog (the page reloads
// with fresh data behind it).
const open = ref(false);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button
                v-tooltip="trans('media.details')"
                type="button"
                :aria-label="trans('media.details')"
                class="hover:border-brand-soft hover:bg-brand/[0.15] border-ink-faint flex h-7 w-7 shrink-0 items-center justify-center rounded-full border bg-transparent transition-colors duration-150"
            >
                <span class="text-ink-secondary font-serif text-sm leading-none font-bold italic">i</span>
            </button>
        </DialogTrigger>
        <DialogContent class="border-line-subtle bg-surface max-w-dialog max-h-[80dvh] overflow-y-auto rounded-2xl p-5 sm:p-7">
            <DialogTitle class="mb-1 text-2xl font-semibold">{{ title }}</DialogTitle>
            <div class="flex flex-col gap-5">
                <template v-for="row in rows" :key="row.label">
                    <div v-if="row.links?.length">
                        <div class="text-ink-muted mb-1 text-sm font-bold">{{ row.label }}</div>
                        <div class="text-ink text-base font-semibold">
                            <template v-for="(link, i) in row.links" :key="link.href">
                                <Link :href="link.href" class="hover:text-brand-soft transition-colors duration-150">{{ link.name }}</Link><span
                                    v-if="i < row.links.length - 1"
                                    >,
                                </span>
                            </template>
                        </div>
                    </div>
                    <div v-else-if="row.value">
                        <div class="text-ink-muted mb-1 text-sm font-bold">{{ row.label }}</div>
                        <div class="text-ink text-base font-semibold">{{ row.value }}</div>
                    </div>
                </template>
                <!-- Developers / publishers: a normal row, but each name links
                     to its company page. -->
                <div v-for="row in linkedCreatorRows" :key="row.label">
                    <div class="text-ink-muted mb-1 text-sm font-bold">{{ row.label }}</div>
                    <div class="text-ink text-base font-semibold">
                        <template v-for="(creator, i) in row.creators" :key="`${creator.name}-${i}`">
                            <Link v-if="creator.href" :href="creator.href" class="hover:text-brand-soft transition-colors duration-150">
                                {{ creator.name }}
                            </Link>
                            <span v-else>{{ creator.name }}</span><span v-if="i < row.creators.length - 1">, </span>
                        </template>
                    </div>
                </div>
                <div v-if="networks?.length">
                    <div class="text-ink-muted mb-2 text-sm font-bold">{{ trans('media.network') }}</div>
                    <div class="flex flex-wrap items-center gap-2">
                        <component
                            :is="networkHref(network) ? Link : 'span'"
                            v-for="network in networks"
                            :key="network.name"
                            v-tooltip="network.logoPath ? network.name : null"
                            :href="networkHref(network) ?? undefined"
                            class="flex items-center rounded-md transition-opacity duration-150"
                            :class="[
                                network.logoPath ? 'bg-paper px-2.5 py-1.5' : 'border-line bg-surface-raised border px-2.5 py-1',
                                networkHref(network) ? 'hover:opacity-80' : '',
                            ]"
                        >
                            <img
                                v-if="network.logoPath"
                                :src="mediaImage(network.logoPath, 'w92') ?? undefined"
                                :alt="network.name"
                                width="92"
                                height="46"
                                loading="lazy"
                                class="h-5 w-auto object-contain"
                            />
                            <span v-else class="text-ink-secondary text-sm font-semibold">{{ network.name }}</span>
                        </component>
                    </div>
                </div>
                <div v-if="studios?.length">
                    <div class="text-ink-muted mb-2 text-sm font-bold">{{ trans('media.studios') }}</div>
                    <div class="flex flex-wrap items-center gap-2">
                        <component
                            :is="studioHref(studio) ? Link : 'span'"
                            v-for="(studio, i) in studios"
                            :key="`${studio.name}-${i}`"
                            v-tooltip="studio.logoPath ? studio.name : null"
                            :href="studioHref(studio) ?? undefined"
                            class="flex items-center rounded-md transition-opacity duration-150"
                            :class="[
                                studio.logoPath ? 'bg-paper px-2.5 py-1.5' : 'border-line bg-surface-raised border px-2.5 py-1',
                                studioHref(studio) ? 'hover:opacity-80' : '',
                            ]"
                        >
                            <img
                                v-if="studio.logoPath"
                                :src="mediaImage(studio.logoPath, 'w92') ?? undefined"
                                :alt="studio.name"
                                width="92"
                                height="46"
                                loading="lazy"
                                class="h-5 w-auto object-contain"
                            />
                            <span v-else class="text-ink-secondary text-sm font-semibold">{{ studio.name }}</span>
                        </component>
                    </div>
                </div>
                <div v-if="keywords?.length">
                    <div class="text-ink-muted mb-2 text-sm font-bold">{{ trans('media.keywords') }}</div>
                    <div class="flex flex-wrap gap-2">
                        <component
                            :is="keyword.ref && keywordType ? Link : 'span'"
                            v-for="keyword in keywords.slice(0, 10)"
                            :key="`${keyword.ref ?? 'plain'}-${keyword.name}`"
                            :href="keyword.ref && keywordType ? route('discover', { type: keywordType, f: { keywords: [keyword.ref] } }) : undefined"
                            class="border-line bg-surface-raised text-ink-secondary rounded-md border px-2.5 py-1 text-sm font-semibold"
                            :class="keyword.ref && keywordType ? 'hover:border-brand hover:text-ink transition-colors duration-150' : ''"
                        >
                            {{ keyword.name }}
                        </component>
                    </div>
                </div>
                <div v-if="links && Object.keys(links).length" class="border-line-subtle border-t pt-2">
                    <div class="text-ink-muted mb-2 text-sm font-bold">{{ trans('media.links') }}</div>
                    <ExternalLinks :links="links" />
                </div>
                <div v-if="refresh" class="border-line-subtle border-t pt-4">
                    <RefreshButton :url="refresh.url" :data="refresh.data" @success="open = false" />
                    <p class="text-ink-faint mt-2 text-sm">{{ trans('media.fetch_the_latest_data_from_the') }}</p>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
