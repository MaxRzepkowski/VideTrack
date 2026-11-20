<script setup lang="ts">
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { surfaceLabel } from '@/lib/tracking';
import type { Surface } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';

export interface GridRow {
    key: string;
    label: string;
    description?: string | null;
    /** Each column's box: ticked, whether it can be changed from here, and whether the row's note explains why not. */
    cells: Partial<Record<Surface, { checked: boolean; disabled?: boolean; explained?: boolean }>>;
}

/**
 * Rows against the surfaces they reach, a box in each column — the shape of
 * the defaults and of every kind of title's own settings alike, so the columns
 * stand in the same place down the page. A single column is a switch, as the
 * notifications' defaults are.
 */
const props = defineProps<{
    /** Unique on the page: the rows' notes are named by it. */
    id: string;
    columns: readonly Surface[];
    rows: GridRow[];
    /** What the rows are, over them; the screen reader's name for the column when the page shows none. */
    heading: string;
    showHeading?: boolean;
    /** What the grid belongs to, for a screen reader: the kind of title, as several grids may be open at once. */
    group?: string;
}>();

const noteId = (row: GridRow): string => `${props.id}-${row.key}-note`;

/** A box's name: the kind of title when the grid has one, the surface, the row. */
function boxLabel(surface: Surface, row: GridRow): string {
    const where =
        props.group === undefined
            ? surfaceLabel(surface)
            : trans('settings.setting_in_group', { group: props.group, setting: surfaceLabel(surface) });

    return trans('settings.setting_in_group', { group: where, setting: row.label });
}

const emit = defineEmits<{ toggle: [key: string, surface: Surface] }>();
</script>

<template>
    <table class="w-full border-collapse">
        <thead>
            <tr>
                <th scope="col" class="pb-3 text-left">
                    <span :class="showHeading ? 'text-ink-secondary text-sm font-semibold' : 'sr-only'">{{ heading }}</span>
                </th>
                <th v-for="surface in columns" :key="surface" scope="col" class="text-ink-muted w-20 pb-3 text-center text-sm font-medium sm:w-32">
                    {{ surfaceLabel(surface) }}
                </th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="row in rows" :key="row.key" class="border-line-subtle border-t">
                <th scope="row" class="py-3.5 pr-4 text-left font-normal">
                    <div class="text-ink text-base font-semibold break-words">{{ row.label }}</div>
                    <div v-if="row.description" :id="noteId(row)" class="text-ink-muted mt-0.5 text-sm">{{ row.description }}</div>
                </th>
                <td v-for="surface in columns" :key="surface" class="py-3.5 text-center">
                    <ToggleSwitch
                        v-if="row.cells[surface] && columns.length === 1"
                        class="align-middle"
                        :model-value="row.cells[surface].checked"
                        :disabled="row.cells[surface].disabled"
                        :label="boxLabel(surface, row)"
                        :described-by="row.cells[surface].explained ? noteId(row) : undefined"
                        @update:model-value="emit('toggle', row.key, surface)"
                    />
                    <input
                        v-else-if="row.cells[surface]"
                        type="checkbox"
                        :checked="row.cells[surface].checked"
                        :disabled="row.cells[surface].disabled"
                        :aria-label="boxLabel(surface, row)"
                        :aria-describedby="row.cells[surface].explained ? noteId(row) : undefined"
                        class="border-line-subtle bg-surface-base accent-brand size-5 rounded border align-middle disabled:cursor-not-allowed disabled:opacity-50"
                        @change="emit('toggle', row.key, surface)"
                    />
                </td>
            </tr>
        </tbody>
    </table>
</template>
