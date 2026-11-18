import { scaleColor, scaleStep, type ScaleGround } from '@/lib/statisticsCharts';
import type { MakerTally } from '@/types/generated';
import { packSiblings } from 'd3-hierarchy';

/**
 * Where each of the map's shades begins, in titles from a country: fixed
 * rather than taken from the period's own spread, so a shade means the same
 * count in every year.
 */
export const COUNTRY_THRESHOLDS = [1, 3, 10] as const;

/** The countries lie on a raised sea: no titles is a step above it, and the first shade starts a step up the scale to stand out from that. */
export const MAP_GROUND: ScaleGround = { none: 'var(--color-line)', first: 2 };

export function countryShade(titles: number): string {
    return scaleColor(scaleStep(titles, COUNTRY_THRESHOLDS), MAP_GROUND);
}

/** The largest bubble's radius when there is room; the area of every other follows its count. */
export const RADIUS_MAX = 108;
/** Air between neighbouring bubbles, whatever their size: they never touch. */
export const GAP = 4;
/** Steps of the search for the largest scale that fits. */
const FIT_STEPS = 24;

/** From this radius a bubble holds its logo or name and its count. */
export const RADIUS_LABELLED = 40;
/** From this radius a bubble holds its logo alone; smaller ones say who they are on hover. */
export const RADIUS_LOGO = 18;

export type Bubble = { maker: MakerTally; x: number; y: number; r: number };
export type BubbleLayout = { bubbles: Bubble[]; width: number; height: number };

/**
 * A bubble per maker, packed around the largest, as large as fits the box:
 * the radii scale together (area still follows the count), the gap between
 * them does not. The layout spans (0, 0) to (width, height), within the box.
 */
export function packBubbles(makers: MakerTally[], box: { width: number; height: number }): BubbleLayout {
    if (makers.length === 0) {
        return { bubbles: [], width: 0, height: 0 };
    }

    const most = Math.max(...makers.map((maker) => maker.titles));
    const fits = (layout: BubbleLayout): boolean => layout.width <= box.width && layout.height <= box.height;
    let high = RADIUS_MAX / Math.sqrt(most);
    let best = pack(makers, high);

    if (fits(best)) {
        return best;
    }

    let low = 0;
    best = pack(makers, 0);

    for (let step = 0; step < FIT_STEPS; step++) {
        const scale = (low + high) / 2;
        const layout = pack(makers, scale);

        if (fits(layout)) {
            low = scale;
            best = layout;
        } else {
            high = scale;
        }
    }

    return best;
}

/** The makers packed with radius `scale * sqrt(titles)` and the gap around each. */
function pack(makers: MakerTally[], scale: number): BubbleLayout {
    const circles: Bubble[] = makers.map((maker) => ({ maker, x: 0, y: 0, r: scale * Math.sqrt(maker.titles) + GAP / 2 }));

    packSiblings(circles);

    const left = Math.min(...circles.map((circle) => circle.x - circle.r));
    const top = Math.min(...circles.map((circle) => circle.y - circle.r));

    return {
        bubbles: circles.map((circle) => ({ maker: circle.maker, x: circle.x - left, y: circle.y - top, r: circle.r - GAP / 2 })),
        width: Math.max(...circles.map((circle) => circle.x + circle.r)) - left,
        height: Math.max(...circles.map((circle) => circle.y + circle.r)) - top,
    };
}
