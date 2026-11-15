/**
 * The geometry both rating charts share — a show's episodes and a saga's
 * films: the rating axis fitted to the values, and where a tooltip hangs off
 * the mark it describes.
 */

export interface RatingScale {
    yMin: number;
    yMax: number;
    /** The whole ratings the axis draws a line at. */
    grid: number[];
    /** The y of a rating inside a plot `height` tall that starts at `top`. */
    yOf: (rating: number) => number;
}

/** The axis around the ratings shown: a little room below the lowest and above the highest, within 0–10. */
export function ratingScale(ratings: number[], top: number, height: number): RatingScale {
    const yMin = Math.max(0, Math.floor(Math.min(...ratings) - 0.4));
    const yMax = Math.min(10, Math.ceil(Math.max(...ratings) + 0.2));
    const grid: number[] = [];

    for (let value = yMin; value <= yMax; value++) {
        grid.push(value);
    }

    return { yMin, yMax, grid, yOf: (rating) => top + height - ((rating - yMin) / Math.max(0.1, yMax - yMin)) * height };
}

export type TooltipAlign = 'left' | 'center' | 'right';

/** Where a tooltip hangs: `x`/`y` in the plot, opening below its mark or above it. */
export interface TooltipAnchor {
    x: number;
    y: number;
    below: boolean;
    align: TooltipAlign;
}

/** Closer to the top than this, a tooltip opens below its mark instead. */
const FLIP_BELOW = 130;

/** How far from either side the first and last marks hang their tooltip inwards. */
const EDGE = 170;

export const TOOLTIP_ALIGN_CLASS: Record<TooltipAlign, string> = {
    left: '',
    center: '-translate-x-1/2',
    right: '-translate-x-full',
};

function alignAt(x: number, width: number): TooltipAlign {
    if (x < EDGE) {
        return 'left';
    }

    return x > width - EDGE ? 'right' : 'center';
}

/** A chart dot's tooltip, in a plot `width` wide. */
export function pointAnchor(x: number, y: number, width: number): TooltipAnchor {
    const below = y < FLIP_BELOW;

    return { x, y: below ? y + 12 : y - 10, below, align: alignAt(x, width) };
}

/** A heatmap cell's tooltip, measured against the plot it sits in. */
export function cellAnchor(cell: DOMRect, plot: DOMRect, width: number): TooltipAnchor {
    const top = cell.top - plot.top;
    const below = top < FLIP_BELOW;
    const x = cell.left - plot.left + cell.width / 2;

    return { x, y: below ? top + cell.height + 4 : top - 4, below, align: alignAt(x, width) };
}
