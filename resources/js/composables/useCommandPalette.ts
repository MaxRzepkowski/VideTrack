import { onMounted, onUnmounted, ref, type Ref } from 'vue';

/** The palette's shortcut as `aria-keyshortcuts` names it: Ctrl or Cmd with K, on any system. */
export const PALETTE_SHORTCUT = 'Control+K Meta+K';

// One open state for the whole app: the palette is mounted in the layout, and
// anything may ask for it (a header button, a keyboard shortcut) without
// owning it.
const open = ref(false);

// The layout re-mounts on every page visit, so the listener is counted rather
// than bound per mount — two listeners would toggle the overlay twice per
// keypress and it would never appear to open.
let listeners = 0;

/** A key that would be typed into something must not be a shortcut. */
function isTyping(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

function onKeydown(event: KeyboardEvent): void {
    // cmd+K / ctrl+K works while typing too (the industry-standard palette
    // shortcut, and the modifier keeps it out of the way of writing).
    if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = !open.value;

        return;
    }

    // A bare `/` only opens the palette where it would not have been typed —
    // and never mid-composition, where it may be part of an IME sequence.
    if (event.key === '/' && !event.metaKey && !event.ctrlKey && !event.altKey && !event.isComposing && !isTyping(event.target)) {
        event.preventDefault();
        open.value = true;
    }
}

export function useCommandPalette(): {
    open: Ref<boolean>;
    close: () => void;
} {
    onMounted(() => {
        if (listeners++ === 0) {
            window.addEventListener('keydown', onKeydown);
        }
    });

    onUnmounted(() => {
        if (--listeners === 0) {
            window.removeEventListener('keydown', onKeydown);
        }
    });

    return {
        open,
        close: () => (open.value = false),
    };
}
