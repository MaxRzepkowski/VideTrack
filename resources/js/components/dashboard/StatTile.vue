<script setup lang="ts">
import Hairline from '@/components/Hairline.vue';
import { durationTerms } from '@/lib/format';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { viewerUsername } from '@/lib/pageContext';
import { amountTerms, statCounts } from '@/lib/progress';
import type { WatchStats } from '@/types/media';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** A media type code, in the registry. */
        kind: string;
        stats?: WatchStats;
        /**
         * Whose numbers these are. The dashboard counts the viewer, so it
         * says nothing; a profile counts the person it is about, and the
         * shortcut below has to land in THEIR history.
         */
        username?: string | null;
    }>(),
    { stats: undefined, username: null },
);

const stats = computed(() => props.stats ?? { minutes: 0, watched: 0, plays: 0, titles: 0, estimatedMinutes: null });

// Glyph and colour come from the shared type vocabulary, so a tile names its
// type exactly the way every badge, calendar entry and countdown card does.
const meta = computed(() => mediaTypeMeta(props.kind));
const icon = computed(() => meta.value.icon);
const color = computed(() => meta.value.color);

// The tile is a shortcut into the history panel, pre-filtered to its type.
// Container types (shows) come back rolled up there, one card per show.
const href = computed(() => route('users.history', { user: props.username ?? viewerUsername(), type: props.kind }));

const label = computed(() => meta.value.plural());

/**
 * Total watch time as "3 days 7 hours 12 minutes" (games: real logged hours
 * only) — the shared spelling, which the progress tooltips quote too. Reading
 * types count positions, not time: the `minutes` field carries the
 * progress-unit total, and the tile leads with the time it took, estimated at
 * the user's pace and marked as an estimate, naming what was read beneath it.
 */
const estimated = computed(() => stats.value.estimatedMinutes !== null);

const headline = computed(() => (estimated.value ? durationTerms(stats.value.estimatedMinutes ?? 0) : amountTerms(props.kind, stats.value.minutes)));

/**
 * How many things are behind the headline. A container names both levels —
 * the headline is time, so neither the show count nor the episode count is
 * implied by it; every other type has only the one level to name.
 */
const detail = computed(() => statCounts(props.kind, stats.value));
</script>

<template>
    <!-- Stat tile: the type's glyph carries the identity
         on the left, the numbers read as one sentence on the right. The whole
         tile is the link into its slice of the history. -->
    <Link
        :href="href"
        data-tooltip-group
        :style="{ '--tile-color': color }"
        class="group border-line-subtle bg-surface focus-visible:ring-brand focus-visible:ring-offset-surface-base relative flex min-w-0 items-stretch overflow-hidden rounded-xl border transition-colors duration-150 hover:border-[var(--tile-color)] focus-visible:border-[var(--tile-color)] focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden"
    >
        <!-- The glyph alone is the type column on the tile's own surface — the
             type colour is carried by it, not by a wash. A hairline down the
             right closes the column: that is the split. -->
        <div class="border-on-photo/5 flex shrink-0 flex-col items-center justify-center gap-2 border-r px-4 py-4">
            <!-- Decorative: the glyph has no visible label, so the type is
                 named for screen readers right before the numbers, which
                 they read on. The tile is `relative` for that name: sr-only
                 is absolutely positioned, and in a tile scrolled out of the
                 carousel it would otherwise escape the row's clipping and
                 widen the whole page. Lucide's default stroke is drawn for
                 16–24px boxes; thinned here so the glyph reads as a mark. -->
            <component :is="icon" class="size-6" :stroke-width="1.5" :style="{ color }" aria-hidden="true" />
            <span class="sr-only">{{ label }}</span>
        </div>

        <div class="flex min-w-0 flex-1 flex-col justify-center px-5 py-4">
            <!-- Each term is its own group: the unit sits tight against its own
                 number, and the space that separates one term from the next is
                 wider than the space inside a term. Otherwise "3 days 7 hours"
                 reads as four loose words instead of two quantities. Number and
                 unit share one size — weight alone tells them apart. -->
            <!-- A step down on a phone: the tile is barely wider than the
                 screen there, and "3 days 7 hours 12 minutes" set at `lg`
                 wraps to three lines inside it. -->
            <p class="text-ink flex flex-wrap items-baseline gap-x-2 text-base leading-tight font-bold sm:text-lg">
                <span v-if="(stats.estimatedMinutes ?? 0) > 0" class="text-ink-muted font-medium">{{ trans('common.estimate_sign') }}</span>
                <span v-for="entry in headline" :key="entry.unit" class="inline-flex items-baseline gap-x-1">
                    <span>{{ entry.value }}</span>
                    <span class="font-medium">{{ entry.unit }}</span>
                </span>
            </p>
            <!-- The shared rule between the counts. The row spaces itself, so
                 the rule drops its own margins. -->
            <div class="text-ink-muted mt-1 flex items-center gap-2 text-sm font-medium">
                <template v-for="(entry, index) in detail" :key="entry">
                    <Hairline v-if="index > 0" class="mx-0" />
                    <span v-tooltip="entry" class="truncate">{{ entry }}</span>
                </template>
            </div>
        </div>
    </Link>
</template>
