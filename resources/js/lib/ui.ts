import type { NonEmpty } from '@/types';

/**
 * Shared class strings for controls that are plain HTML elements, not
 * components — a `<select>` keeps its own `v-model` / `:value` + `@change`
 * binding, so wrapping it would cost more than it saves. One definition here
 * instead of the same twenty utilities copied into every view.
 */

/**
 * Filter / sort select, standing on the page background. `h-10` is the shared
 * toolbar control height — the search box, the Filters button, the sort toggle
 * and the view switch all stand at 40px, so a row of them lines up.
 */
export const SELECT_CLASS =
    'border-line bg-surface text-ink hover:border-line-strong focus:border-brand h-10 cursor-pointer rounded-lg border px-3.5 text-base font-semibold transition-colors duration-150 outline-none';

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
