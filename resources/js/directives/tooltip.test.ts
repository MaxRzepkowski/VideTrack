import { hideTooltip, tooltip } from '@/lib/tooltip';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

const TITLE = 'The Lord of the Rings: The Fellowship of the Ring';

// The same text with a hint that shows always, or only while the text is cut short.
const Title = defineComponent({
    props: { onlyWhenCut: Boolean },
    setup: () => ({ TITLE }),
    template: `<span v-if="onlyWhenCut" v-tooltip.truncated="TITLE" class="truncate">{{ TITLE }}</span>
        <span v-else v-tooltip="TITLE" class="truncate">{{ TITLE }}</span>`,
});

/** The text as the browser would lay it out against the room it has, across and down. */
function laidOut(text: { width: number; height: number }, room: { width: number; height: number }): void {
    vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(text.width);
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(room.width);
    vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockReturnValue(text.height);
    vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockReturnValue(room.height);
}

const LINE = 20;

function mounted(onlyWhenCut: boolean) {
    vi.useFakeTimers();

    return mount(Title, { props: { onlyWhenCut }, attachTo: document.body });
}

/** A pointer coming to rest on the text, for as long as the bubble waits. */
function hover(element: Element): void {
    element.dispatchEvent(new PointerEvent('pointerenter', { pointerType: 'mouse' }));
    vi.runAllTimers();
}

afterEach(() => {
    hideTooltip();
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('v-tooltip.truncated', () => {
    it('shows the full title when the title is cut short', () => {
        laidOut({ width: 480, height: LINE }, { width: 200, height: LINE });
        hover(mounted(true).element);

        expect(tooltip.value?.content).toBe(TITLE);
    });

    it('shows it when the title is clamped to fewer lines than it needs', () => {
        laidOut({ width: 200, height: 3 * LINE }, { width: 200, height: 2 * LINE });
        hover(mounted(true).element);

        expect(tooltip.value?.content).toBe(TITLE);
    });

    it('says nothing over a title shown whole', () => {
        laidOut({ width: 200, height: LINE }, { width: 200, height: LINE });
        hover(mounted(true).element);

        expect(tooltip.value).toBeNull();
    });

    it('measures when the bubble would open, not when the pointer arrives', () => {
        laidOut({ width: 480, height: LINE }, { width: 0, height: LINE });
        const element = mounted(true).element;

        element.dispatchEvent(new PointerEvent('pointerenter', { pointerType: 'mouse' }));
        // The row slides open under the pointer before the delay is over.
        laidOut({ width: 200, height: LINE }, { width: 240, height: LINE });
        vi.runAllTimers();

        expect(tooltip.value).toBeNull();
    });

    it('leaves a plain hint alone over the same text shown whole', () => {
        laidOut({ width: 200, height: LINE }, { width: 200, height: LINE });
        hover(mounted(false).element);

        expect(tooltip.value?.content).toBe(TITLE);
    });
});
