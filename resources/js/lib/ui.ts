import type { NavGroupId } from '@/lib/navigation';
import type { NonEmpty } from '@/types';

/**
 * Shared looks of plain HTML controls and of menu rows: class strings for
 * elements that are not components (a `<select>` keeps its own `v-model` /
 * `:value` + `@change` binding, so wrapping it would cost more than it saves)
 * and the colour properties a menu row reads. One definition here instead of
 * the same twenty utilities copied into every view.
 */

/** The colours a menu row can wear: its group's, or the danger colour for a row that is not a place. */
export type RowTint = NavGroupId | 'danger';

// Tokens of the glyph, of the square under a pointed row, and of the glyph on that square.
const TINT_TOKENS: Record<RowTint, readonly [string, string, string]> = {
    library: ['nav-library', 'nav-library-fill', 'nav-library-ink'],
    discover: ['nav-discover', 'nav-discover-fill', 'nav-discover-ink'],
    releases: ['nav-releases', 'nav-releases-fill', 'nav-releases-ink'],
    danger: ['danger', 'danger-strong', 'on-accent'],
};

/** A tint as the custom properties a menu row (`MenuRow`) reads from an ancestor. */
export function rowTint(tint: RowTint): Record<'--tint' | '--tint-fill' | '--tint-ink', string> {
    const [glyph, fill, ink] = TINT_TOKENS[tint];

    return { '--tint': `var(--color-${glyph})`, '--tint-fill': `var(--color-${fill})`, '--tint-ink': `var(--color-${ink})` };
}

/**
 * A word of a menu row: the main bar, the profile's panels and the tabs of the
 * tracking settings read alike, as one kind of control. Quiet on a dark tile
 * until it is the page (or tab) you are on.
 */
export const NAV_WORD_CLASS =
    'flex h-10 shrink-0 items-center gap-2 rounded-[10px] px-3.5 text-base font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-ink';

/** The tile and text of a menu word: lit for the page you are on. */
export function navWordState(current: boolean): string {
    return current ? 'bg-surface-raised text-ink' : 'bg-surface-toolbar text-ink-muted hover:bg-surface hover:text-ink';
}

/**
 * Filter / sort select, standing on the page background. `h-10` is the shared
 * toolbar control height — the search box, the Filters button, the sort toggle
 * and the view switch all stand at 40px, so a row of them lines up.
 */
export const SELECT_CLASS =
    'border-line bg-surface text-ink hover:border-line-strong focus:border-brand h-10 cursor-pointer rounded-lg border px-3.5 text-base font-semibold transition-colors duration-150 outline-none';

/**
 * A square icon button at the shared 40px control height (previous / next, a
 * feed link). Dimmed while it has nowhere to go.
 */
export const ICON_BUTTON_CLASS =
    'border-line bg-surface text-ink-secondary hover:border-line-strong flex size-10 items-center justify-center rounded-lg border transition-colors duration-150 disabled:cursor-default disabled:opacity-40';

/**
 * The same select inside a `bg-surface` card (ratings panels, watch
 * providers) — one step up, or it sinks into the card it sits on.
 */
export const SELECT_CLASS_ON_CARD = SELECT_CLASS.replace('bg-surface ', 'bg-surface-raised ');

/**
 * A settings page's select or text field — the settings forms' own look,
 * taller and quieter than a toolbar select. Width and spacing are the
 * caller's: a field fills its card, a select beside a label shrinks to fit.
 */
export const SETTINGS_FIELD_CLASS =
    'border-line bg-surface-base focus:border-brand rounded-md border px-3 py-2 text-base shadow-sm focus:outline-none';

/** One setting's card on a settings page. */
export const SETTINGS_CARD_CLASS = 'border-line-subtle bg-surface rounded-xl border px-5 py-4';

export interface PosterGridStep {
    /** Where the step starts (a Tailwind breakpoint), `null` for the base. */
    minWidth: string | null;
    columns: number;
    className: string;
}

/**
 * The poster card grid's column steps — the one table both the classes and
 * `usePosterColumns` (which trims shelves to whole rows) read, so the two
 * cannot drift apart.
 *
 * The steps match the dashboard calendar (three from `sm`, four from `lg`,
 * five from `xl`); a column sooner and the action bar's four cells get too
 * narrow for their contents. A sidebar only exists from `lg` up, so the
 * four-wide variant shares everything below it and stays a column behind.
 * Class names written out in full — Tailwind reads them, it cannot build them.
 */
export const POSTER_GRID_STEPS: Record<4 | 5, NonEmpty<PosterGridStep>> = {
    5: [
        { minWidth: null, columns: 2, className: 'grid-cols-2' },
        { minWidth: '40rem', columns: 3, className: 'sm:grid-cols-3' },
        { minWidth: '64rem', columns: 4, className: 'lg:grid-cols-4' },
        { minWidth: '80rem', columns: 5, className: 'xl:grid-cols-5' },
    ],
    4: [
        { minWidth: null, columns: 2, className: 'grid-cols-2' },
        { minWidth: '40rem', columns: 3, className: 'sm:grid-cols-3' },
        { minWidth: '80rem', columns: 4, className: 'xl:grid-cols-4' },
    ],
};

/**
 * The poster card grid — render it through `MediaGrid`; the string is here for
 * the one grid that cannot be that component (the list editor's
 * `TransitionGroup`).
 */
export function posterGridClass(widest: 4 | 5 = 5): string {
    return ['xs:gap-5 grid gap-3', ...POSTER_GRID_STEPS[widest].map((step) => step.className)].join(' ');
}

/**
 * `sizes` for a poster inside that grid, step for step: a quarter of the
 * screen from `lg`, and a fixed width from `xl`, where the page container
 * stops growing and five columns stay about 260px each.
 */
export const POSTER_CARD_SIZES = '(min-width: 1280px) 260px, (min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw';
