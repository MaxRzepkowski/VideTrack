import { ref } from 'vue';

/** One line of a tabular tooltip: what is being measured, and the measurement. */
export type TooltipRow = { label: string; value: string };

/**
 * A hint is either a sentence — the icon-button case — or a short table of
 * label/value rows, for a control that stands for several numbers at once
 * (the episode progress bar).
 */
export type TooltipContent = string | TooltipRow[];

export type TooltipState = {
    content: TooltipContent;
    /** Viewport rect of the trigger, read at open time; the bubble is fixed. */
    rect: DOMRect;
    /** The trigger that opened the bubble, used to dismiss mobile tap hints. */
    trigger: HTMLElement;
};

/** The content as one line, for the accessibility tree. */
export function tooltipText(content: TooltipContent): string {
    return typeof content === 'string' ? content : content.map((row) => `${row.label}: ${row.value}`).join(', ');
}

/**
 * The state behind the app's single tooltip bubble. One bubble is enough —
 * only one element can be hovered or focused at a time — so instead of a
 * wrapper component around every control, triggers opt in with `v-tooltip`
 * and push their label here.
 */
export const tooltip = ref<TooltipState | null>(null);

/** How long the pointer must rest on a trigger before the bubble appears. */
const OPEN_DELAY = 400;

/**
 * Sweeping across a row of icon buttons should not re-run the delay on every
 * one of them: right after a bubble closes, the next opens instantly.
 */
const SKIP_DELAY = 300;

/**
 * What "the same place" means for that shortcut. A card marks itself with this
 * attribute, so its own buttons, its bar and its title pass the bubble between
 * them without a pause — while the pointer moving on to the NEXT card crosses a
 * boundary and waits out the full delay again, the way it did on arrival.
 * Rows of controls that belong to no card (the header, a toolbar) share the
 * "no group" group and keep sweeping as before.
 */
const GROUP_SELECTOR = '[data-tooltip-group]';

let timer: ReturnType<typeof setTimeout> | undefined;
let closedAt = 0;

/** Group of the trigger the visible bubble belongs to, kept after it closes. */
let shownGroup: Element | null = null;

/** The trigger whose bubble is waiting out its delay. */
let pending: HTMLElement | null = null;

/**
 * Opens the bubble after the hover delay. `stillWanted`, when given, is asked
 * at that moment rather than now: what it measures (a title cut short) may
 * change while the pointer rests, a row sliding open under it for one.
 */
export function showTooltip(el: HTMLElement, content: TooltipContent, stillWanted: () => boolean = () => true): void {
    clearTimeout(timer);
    pending = el;

    const group = el.closest(GROUP_SELECTOR);
    const continues = Date.now() - closedAt < SKIP_DELAY && group === shownGroup;

    timer = setTimeout(
        () => {
            pending = null;

            if (!stillWanted()) {
                return;
            }

            shownGroup = group;
            tooltip.value = { content, rect: el.getBoundingClientRect(), trigger: el };
        },
        continues ? 0 : OPEN_DELAY,
    );
}

/** Tap hints must appear immediately; a hover delay after a deliberate tap
 * reads as a missed interaction on touch screens. */
export function showTooltipImmediately(el: HTMLElement, content: TooltipContent): void {
    clearTimeout(timer);
    pending = null;
    shownGroup = el.closest(GROUP_SELECTOR);
    tooltip.value = { content, rect: el.getBoundingClientRect(), trigger: el };
}

export function hideTooltip(): void {
    clearTimeout(timer);
    pending = null;

    if (tooltip.value) {
        closedAt = Date.now();
        tooltip.value = null;
    }
}

/**
 * A trigger going away takes its own bubble with it, shown or on its way —
 * never another's: a neighbouring card swapped out by a write must not close
 * the bubble under the pointer.
 */
export function hideTooltipOf(el: HTMLElement): void {
    if (pending === el || tooltip.value?.trigger === el) {
        hideTooltip();
    }
}
