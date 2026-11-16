import type { LucideIcon } from '@lucide/vue';

/** One headline fact of a title as a tile: an icon, what it is and its value. */
export interface FactTile {
    key: string;
    icon: LucideIcon;
    label: string;
    value: string;
}
