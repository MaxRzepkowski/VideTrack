<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { countryName, formatCalendarDate, relativeTime, todayDateKey, withCount } from '@/lib/format';
import type { ReleaseEntry } from '@/types/media';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Clapperboard, Disc, MonitorPlay } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    /** Every dated release worldwide, pre-sorted oldest first by the provider. */
    releases: ReleaseEntry[];
    /** The film's primary release date — stands in when no premiere row exists. */
    releaseDate: string | null;
    /**
     * Countries where JustWatch has an offer of any kind, worldwide. A second,
     * dateless source: it knows WHERE a film plays, never since when.
     */
    offerRegions?: string[];
}>();

type Kind = ReleaseEntry['kind'];

/**
 * The tiles speak in moments, not in TMDB's six types: a festival premiere, a
 * limited run and wide distribution all answer "when could anyone see it in a
 * cinema", so they share one tile. The exact type is what the dialog is for.
 */
type Group = 'premiere' | 'digital' | 'disc' | 'tv';

const GROUPS = ['premiere', 'digital', 'disc', 'tv'] as const;

/**
 * TV broadcast is not a tile. It belongs to a film's afterlife — Star Wars
 * first aired on television ten years after its premiere — and a fourth tile
 * would give that the same weight as "when can I watch this". The dialog still
 * carries it as a tab, so the dates are there for whoever goes looking.
 */
const TILE_GROUPS = ['premiere', 'digital', 'disc'] as const;

const GROUP_KINDS: Record<Group, readonly Kind[]> = {
    premiere: ['premiere', 'limited', 'theatrical'],
    digital: ['digital'],
    disc: ['disc'],
    tv: ['tv'],
};

const KIND_LABELS: Record<Kind, () => string> = {
    premiere: () => trans('media.premiere'),
    limited: () => trans('media.limited_release'),
    theatrical: () => trans('media.theatrical'),
    digital: () => trans('media.digital'),
    disc: () => trans('media.disc'),
    tv: () => trans('media.release_kind_tv'),
};

const kindLabel = (kind: Kind): string => KIND_LABELS[kind]();

// The icon carries the KIND, the colour carries the TIME (green = behind us,
// amber = still ahead) — two independent facts, so they get two channels
// rather than fighting over one.
const kindIcon = { premiere: Clapperboard, digital: MonitorPlay, disc: Disc } as const;

const today = todayDateKey();

const entriesOf = (group: Group): ReleaseEntry[] => props.releases.filter((entry) => GROUP_KINDS[group].includes(entry.kind));

/**
 * One tile per kind, each carrying the EARLIEST date worldwide rather than the
 * viewer's own country. TMDB's release data is crowdsourced and thin per
 * country — a film routinely holds a digital date in a handful of regions and
 * none in the viewer's — so a local-only reading reports "nothing" for films
 * long since out. Per-country detail is what the dialog is for.
 */
const offers = computed<string[]>(() => props.offerRegions ?? []);

/**
 * The second source only speaks where the first is silent. Release dates are
 * volunteer-entered and lag the licensing feeds JustWatch runs on, so a film
 * can be buyable online while carrying no digital release date at all (Fall 2:
 * Deadpoint — 39 release rows, every one theatrical, yet on sale in the US).
 * Where a date does exist it wins, because it also answers "since when".
 */
const datelessDigital = computed(() => entriesOf('digital').length === 0 && offers.value.length > 0);

/** Sorts a dateless tile last — there is no moment to place it at. */
const NO_DATE = '9999-12-31';

const countOf = (group: Group): number => (group === 'digital' && datelessDigital.value ? offers.value.length : entriesOf(group).length);

const tiles = computed(() =>
    TILE_GROUPS.map((kind) => {
        const entries = entriesOf(kind);
        // The provider sorts oldest first, so the first match is the earliest.
        // A film that went straight to streaming has no theatrical row at all;
        // its own release date stands in so the premiere never goes missing.
        const date = entries[0]?.date ?? (kind === 'premiere' ? props.releaseDate : null);

        if (!date) {
            return kind === 'digital' && datelessDigital.value
                ? {
                      kind,
                      raw: NO_DATE,
                      label: kindLabel(kind),
                      date: trans('media.available'),
                      distance: null,
                      icon: kindIcon[kind],
                      tone: 'text-action-watched',
                      countries: offers.value.length,
                      // Doubles as the attribution TMDB's terms require
                      // wherever watch-provider data is rendered.
                      source: trans('media.justwatch'),
                  }
                : null;
        }

        const future = date > today;

        return {
            kind,
            raw: date,
            label: kindLabel(kind),
            date: formatCalendarDate(date) ?? '',
            // Only what is still ahead gets a countdown — "8 years ago" on
            // every row of a 2017 film is noise, not information.
            distance: future ? relativeTime(date) : null,
            icon: kindIcon[kind],
            tone: future ? 'text-status-onhold' : 'text-action-watched',
            countries: entries.length,
            source: null as string | null,
        };
    })
        .filter((tile): tile is NonNullable<typeof tile> => tile !== null)
        // Chronological, not a fixed premiere→digital→disc order: a streaming
        // original can reach VOD before its token cinema run (The Runner hit
        // Prime on 2 Sep and cinemas in Kazakhstan on the 3rd), and a fixed
        // order would render that as an error rather than a timeline.
        .sort((a, b) => (a.raw < b.raw ? -1 : 1)),
);

const tabs = computed<Group[]>(() => GROUPS.filter((group) => countOf(group) > 0));

const open = ref(false);
const tab = ref<Group>('premiere');

const rows = computed(() =>
    tab.value === 'digital' && datelessDigital.value
        ? offers.value.map((region) => ({
              region,
              name: countryName(region),
              kind: null as string | null,
              note: null as string | null,
              formatted: trans('common.n_a'),
          }))
        : entriesOf(tab.value).map((entry) => ({
              region: entry.region,
              name: countryName(entry.region),
              // Only the cinema tab holds several types; naming the type under
              // the single-type tabs would repeat their own heading.
              kind: GROUP_KINDS[tab.value].length > 1 ? kindLabel(entry.kind) : null,
              note: entry.note,
              formatted: formatCalendarDate(entry.date) ?? '',
          })),
);

function openAt(group: Group): void {
    tab.value = countOf(group) ? group : (tabs.value[0] ?? 'premiere');
    open.value = true;
}
</script>

<template>
    <section v-if="tiles.length">
        <SectionHeading :title="trans('media.releases')" />

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <component
                :is="tile.countries ? 'button' : 'div'"
                v-for="tile in tiles"
                :key="tile.kind"
                :type="tile.countries ? 'button' : undefined"
                class="border-line-subtle bg-surface flex items-start gap-3 rounded-xl border p-4 text-left"
                :class="tile.countries ? 'hover:border-brand cursor-pointer transition-colors duration-150' : ''"
                @click="tile.countries && openAt(tile.kind)"
            >
                <!-- Icon column at the size the action panel uses, so the two
                     sides of the page read as one system. -->
                <component :is="tile.icon" class="mt-0.5 size-6 shrink-0" :class="tile.tone" aria-hidden="true" />

                <!-- The kind is the caption, the date is the answer — so the
                     date carries the weight and the label steps back. -->
                <span class="flex min-w-0 flex-col gap-1">
                    <span class="text-ink-muted text-sm font-semibold">{{ tile.label }}</span>
                    <span class="text-ink text-lg leading-tight font-semibold">{{ tile.date }}</span>
                    <span class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                        <span v-if="tile.distance" class="text-status-onhold text-sm font-semibold">{{ tile.distance }}</span>
                        <MetaLine
                            v-if="tile.countries"
                            :parts="[transChoice('media.count_country', tile.countries), tile.source]"
                            class="text-ink-faint text-sm"
                        />
                    </span>
                </span>
            </component>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="border-line-subtle bg-surface max-w-panel flex max-h-[85dvh] flex-col rounded-2xl p-4 sm:p-7">
                <DialogTitle class="shrink-0 pr-10 text-2xl font-semibold">{{ trans('media.releases') }}</DialogTitle>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <button
                        v-for="kind in tabs"
                        :key="kind"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors duration-150"
                        :class="tab === kind ? 'bg-brand text-on-accent' : 'bg-surface-raised text-ink-muted'"
                        :aria-pressed="tab === kind"
                        @click="tab = kind"
                    >
                        {{ withCount(kindLabel(kind), countOf(kind)) }}
                    </button>
                </div>

                <div class="overflow-y-auto">
                    <ul class="m-0 grid list-none grid-cols-1 gap-x-8 gap-y-1 p-0 sm:grid-cols-2">
                        <li
                            v-for="(row, index) in rows"
                            :key="`${row.region}-${row.formatted}-${index}`"
                            class="border-line-subtle flex items-baseline justify-between gap-4 border-b py-2 text-sm last:border-b-0"
                        >
                            <span class="flex min-w-0 flex-col">
                                <span class="text-ink font-medium">{{ row.name }}</span>
                                <!-- The exact type and whatever TMDB's
                                     volunteers wrote next to the date. -->
                                <MetaLine v-if="row.kind || row.note" :parts="[row.kind, row.note]" class="text-ink-faint text-sm" />
                            </span>
                            <span class="text-ink-muted shrink-0 tabular-nums">{{ row.formatted }}</span>
                        </li>
                    </ul>
                </div>
            </DialogContent>
        </Dialog>
    </section>
</template>
