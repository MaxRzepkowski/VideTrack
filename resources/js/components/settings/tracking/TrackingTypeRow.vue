<script setup lang="ts">
import TrackingGrid, { type GridRow } from '@/components/settings/tracking/TrackingGrid.vue';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import {
    flippedValue,
    hasOwnSources,
    isHeldOff,
    sourcesTaken,
    type DefaultSources,
    optionIsOn,
    switchesIn,
    TAB_SURFACES,
    trackingSources,
    typeSummary,
    type SettingReader,
    type OwnSources,
    type TypeTab,
} from '@/lib/tracking';
import type { Surface, TrackingSource, TypeOption, TypeOptionSwitch, TypePanel } from '@/types/generated';
import { ChevronDown } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

/**
 * One kind of title in a tab of the tracking page: a row saying in plain
 * words what it does, opening onto its own sources and the settings only it
 * has.
 */
const props = defineProps<{
    panel: TypePanel;
    tab: TypeTab;
    read: SettingReader;
    own: OwnSources | undefined;
    defaults: DefaultSources;
}>();

// Every change is told as what was asked for, not as the state it leads to:
// the page applies it to the form as it stands, so quick clicks in a row,
// before this row has been drawn again, each count.
const emit = defineEmits<{
    set: [preference: string, value: boolean | string];
    own: [on: boolean];
    source: [surface: Surface, row: TrackingSource];
}>();

const open = ref(false);

const meta = computed(() => mediaTypeMeta(props.panel.type));
const columns = computed(() => TAB_SURFACES[props.tab]);
const options = computed(() => props.panel.options.filter((option) => switchesIn(option, props.tab).length > 0));
const summary = computed(() => typeSummary(props.panel, props.tab, props.read, props.own, props.defaults));
const ownOn = computed(() => hasOwnSources(props.own, props.tab));
const bodyId = computed(() => `tracking-${props.tab}-${props.panel.type}`);

const sourceRows = computed<GridRow[]>(() =>
    trackingSources().map((source) => ({
        key: source.key,
        label: source.label,
        cells: Object.fromEntries(columns.value.map((surface) => [surface, { checked: props.own?.[surface]?.includes(source.key) ?? false }])),
    })),
);

const optionRows = computed<GridRow[]>(() =>
    options.value.map((option) => {
        const switches = switchesIn(option, props.tab);
        // Showing something of the titles, an option has nothing to show where the type takes none.
        const empty = (at: TypeOptionSwitch): boolean =>
            option.ofTrackedTitles && sourcesTaken(props.own, props.tab, props.defaults, at.surface).length === 0;
        // A default switched off holds the option off here, whatever it says: the row says which.
        const heldBy = switches.map((at) => (isHeldOff(at, props.read) ? at.requires : null)).find((requirement) => requirement != null);
        const note = heldBy
            ? trans('settings.needs_on_in_the_defaults', { setting: trans(heldBy.label) })
            : switches.some(empty)
              ? trans('settings.takes_no_titles_there')
              : null;

        return {
            key: option.key,
            label: trans(option.label),
            description: note ?? (option.description === null ? null : trans(option.description)),
            cells: Object.fromEntries(
                switches.map((at) => [
                    at.surface,
                    // Held off, it reads as off, as it works; the choice behind it waits unchanged.
                    isHeldOff(at, props.read) || empty(at)
                        ? { checked: false, disabled: true, explained: true }
                        : {
                              checked: optionIsOn(option, props.read(at.preference)),
                              disabled: flippedValue(options.value, option, at, props.read) === null,
                          },
                ]),
            ),
        };
    }),
);

function toggleSource(row: string, surface: Surface): void {
    const source = trackingSources().find((candidate) => candidate.key === row);

    if (source !== undefined) {
        emit('source', surface, source.key);
    }
}

function toggleOption(key: string, surface: Surface): void {
    const option: TypeOption | undefined = options.value.find((candidate) => candidate.key === key);
    const at = option?.switches.find((candidate) => candidate.surface === surface);
    const value = option && at ? flippedValue(options.value, option, at, props.read) : null;

    if (at !== undefined && value !== null) {
        emit('set', at.preference, value);
    }
}
</script>

<template>
    <div class="py-4 first:pt-0 last:pb-0">
        <button
            :id="`${bodyId}-row`"
            type="button"
            :aria-expanded="open"
            :aria-controls="open ? bodyId : undefined"
            class="flex w-full items-center gap-3 text-left"
            @click="open = !open"
        >
            <component :is="meta.icon" class="size-5 shrink-0" :style="{ color: meta.color }" aria-hidden="true" />
            <span class="min-w-0 flex-1">
                <span class="text-ink block text-base font-semibold">{{ meta.plural() }}</span>
                <span class="text-ink-muted mt-0.5 block truncate text-sm" :title="summary">{{ summary }}</span>
            </span>
            <ChevronDown
                class="text-ink-muted size-5 shrink-0 transition-transform duration-150"
                :class="{ 'rotate-180': open }"
                aria-hidden="true"
            />
        </button>

        <div v-if="open" :id="bodyId" class="mt-5 space-y-5 sm:pl-8">
            <div class="flex items-center justify-between gap-6">
                <div class="min-w-0">
                    <div class="text-ink text-base font-semibold">{{ trans('settings.own_sources') }}</div>
                    <p class="text-ink-muted mt-0.5 text-sm">
                        {{ ownOn ? trans('settings.copied_from_the_defaults_a_later') : trans('settings.which_titles_this_type_takes') }}
                    </p>
                </div>
                <ToggleSwitch
                    :model-value="ownOn"
                    :label="trans('settings.setting_in_group', { group: meta.plural(), setting: trans('settings.own_sources') })"
                    @update:model-value="emit('own', $event)"
                />
            </div>

            <TrackingGrid
                v-if="ownOn"
                :id="`${bodyId}-sources`"
                :columns
                :rows="sourceRows"
                :heading="trans('settings.sources')"
                :group="meta.plural()"
                show-heading
                @toggle="toggleSource"
            />

            <TrackingGrid
                v-if="optionRows.length > 0"
                :id="`${bodyId}-options`"
                :columns
                :rows="optionRows"
                :heading="tab === 'views' ? trans('settings.what_it_shows') : trans('settings.about_what')"
                :group="meta.plural()"
                show-heading
                @toggle="toggleOption"
            />
        </div>
    </div>
</template>
