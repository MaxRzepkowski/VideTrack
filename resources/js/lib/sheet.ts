/** How far down, in pixels, a sheet pulled by its handle must travel to close on release. */
export const SHEET_CLOSE_DISTANCE = 80;

/** Or how fast, in pixels a millisecond: a quick flick closes it short of the distance. */
export const SHEET_CLOSE_SPEED = 0.5;

/**
 * Whether letting go of a sheet pulled `distance` pixels down over `ms`
 * milliseconds closes it; otherwise it springs back up.
 */
export function sheetDragCloses(distance: number, ms: number): boolean {
    if (distance <= 0) {
        return false;
    }

    return distance >= SHEET_CLOSE_DISTANCE || distance / Math.max(ms, 1) >= SHEET_CLOSE_SPEED;
}
