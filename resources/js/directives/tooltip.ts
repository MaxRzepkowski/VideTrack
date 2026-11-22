import { hideTooltip, hideTooltipOf, showTooltip, showTooltipImmediately, tooltipText, type TooltipContent } from '@/lib/tooltip';
import type { Directive } from 'vue';

/** `id` of the single bubble, for `aria-describedby`. */
export const TOOLTIP_ID = 'app-tooltip';

const labels = new WeakMap<HTMLElement, TooltipContent>();

/** Elements whose `aria-label` we wrote ourselves, so we may rewrite it. */
const named = new WeakSet<HTMLElement>();

/** Elements that show their bubble only while their text is cut short (`v-tooltip.truncated`). */
const whenCut = new WeakSet<HTMLElement>();

/**
 * Whether an element's bubble says something its text does not already show:
 * any hint does; a `truncated` one only while its text is cut short.
 */
function adds(el: HTMLElement): boolean {
    return !whenCut.has(el) || el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1;
}

function open(event: Event): void {
    const el = event.currentTarget as HTMLElement;
    const content = labels.get(el);

    // Measured when the bubble would open, after the hover delay, not on entry.
    if (content !== undefined) {
        showTooltip(el, content, () => adds(el));
    }
}

function onPointerEnter(event: Event): void {
    // Touch has no hover: a tap is the action, and a bubble would only cover
    // whatever the tap just opened.
    if (event instanceof PointerEvent && event.pointerType === 'touch') {
        return;
    }

    open(event);
}

function onFocus(event: Event): void {
    // Keyboard focus only. Focus landing on a button because it was clicked,
    // or because a dialog closed, must not pop a bubble.
    if ((event.currentTarget as HTMLElement).matches(':focus-visible')) {
        open(event);
    }
}

function onPointerLeave(event: Event): void {
    // A touch pointer leaves immediately after a tap. Closing here would make
    // a tap-enabled progress hint disappear in the same gesture that opened it.
    if (event instanceof PointerEvent && event.pointerType === 'touch') {
        return;
    }

    hideTooltip();
}

function close(): void {
    hideTooltip();
}

function onClick(event: Event): void {
    const el = event.currentTarget as HTMLElement;

    // Taps open the tooltip only below the `xs` breakpoint, read from the very token the layout uses.
    const xs = getComputedStyle(document.documentElement).getPropertyValue('--breakpoint-xs').trim() || '30rem';

    if (!el.hasAttribute('data-tooltip-tap') || !window.matchMedia(`(width < ${xs})`).matches) {
        return;
    }

    const content = labels.get(el);

    if (content !== undefined && adds(el)) {
        showTooltipImmediately(el, content);
    }
}

const events: [string, EventListener][] = [
    ['pointerenter', onPointerEnter],
    ['pointerleave', onPointerLeave],
    ['pointerdown', close],
    ['click', onClick],
    ['focus', onFocus],
    ['blur', close],
];

/**
 * Wires the label into the accessibility tree, without saying it twice:
 * an icon-only control has no name of its own, so the tooltip text becomes
 * its `aria-label`; a control that is already named the same way needs
 * nothing; anything else gets the bubble as its description.
 */
function label(el: HTMLElement, text: string): void {
    const own = named.has(el) ? null : el.getAttribute('aria-label');
    const visible = (el.textContent ?? '').trim();

    if (own === null && visible === '' && !el.hasAttribute('aria-labelledby')) {
        el.setAttribute('aria-label', text);
        named.add(el);
        el.removeAttribute('aria-describedby');

        return;
    }

    if (named.has(el)) {
        el.setAttribute('aria-label', text);

        return;
    }

    // Already said out loud — by the element's own name, or by text it renders
    // (an `sr-only` label counts). A description repeating it is noise.
    if (own === text || visible.includes(text)) {
        el.removeAttribute('aria-describedby');

        return;
    }

    el.setAttribute('aria-describedby', TOOLTIP_ID);
}

function sync(el: HTMLElement, value: TooltipContent | null | undefined): void {
    const content = typeof value === 'string' ? value.trim() : (value ?? []);
    const text = tooltipText(content);

    if (text === '') {
        labels.delete(el);
        el.removeAttribute('aria-describedby');

        return;
    }

    labels.set(el, content);
    label(el, text);
}

/**
 * `v-tooltip="label"` on any element — button, link, truncated text. The value
 * is a sentence, or label/value rows for a table of numbers. A falsy value
 * disables it, so a conditional hint is just a ternary. `v-tooltip.truncated`
 * on text that may be cut short (a long title) shows the full text only while
 * it is cut: a title shown whole needs no bubble repeating it.
 */
export const vTooltip: Directive<HTMLElement, TooltipContent | null | undefined, 'truncated'> = {
    mounted(el, binding) {
        events.forEach(([name, handler]) => el.addEventListener(name, handler));

        // Modifiers are written into the template: an element keeps the ones it was born with.
        if (binding.modifiers.truncated === true) {
            whenCut.add(el);
        }

        sync(el, binding.value);
    },
    updated(el, binding) {
        sync(el, binding.value);
    },
    beforeUnmount(el) {
        events.forEach(([name, handler]) => el.removeEventListener(name, handler));
        labels.delete(el);
        hideTooltipOf(el);
    },
};

declare module 'vue' {
    interface GlobalDirectives {
        vTooltip: typeof vTooltip;
    }
}
