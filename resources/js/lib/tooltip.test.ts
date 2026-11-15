import { hideTooltip, hideTooltipOf, showTooltip, showTooltipImmediately, tooltip, tooltipText } from '@/lib/tooltip';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function trigger(group?: HTMLElement): HTMLElement {
    const el = document.createElement('button');
    (group ?? document.body).appendChild(el);

    return el;
}

beforeEach(() => vi.useFakeTimers());

afterEach(() => {
    hideTooltip();
    vi.advanceTimersByTime(1000);
    vi.useRealTimers();
});

describe('tooltipText', () => {
    it('reads a sentence as is and a table as label: value pairs', () => {
        expect(tooltipText('Rate')).toBe('Rate');
        expect(
            tooltipText([
                { label: 'Watched', value: '3' },
                { label: 'Left', value: '2' },
            ]),
        ).toBe('Watched: 3, Left: 2');
    });
});

describe('the bubble', () => {
    it('opens after the pointer rests on a trigger', () => {
        showTooltip(trigger(), 'Rate');

        vi.advanceTimersByTime(399);
        expect(tooltip.value).toBeNull();

        vi.advanceTimersByTime(1);
        expect(tooltip.value?.content).toBe('Rate');
    });

    it('passes straight to the next trigger of the same group', () => {
        const card = document.createElement('div');
        card.setAttribute('data-tooltip-group', '');
        document.body.appendChild(card);

        showTooltip(trigger(card), 'First');
        vi.advanceTimersByTime(400);
        hideTooltip();

        showTooltip(trigger(card), 'Second');
        vi.advanceTimersByTime(0);
        expect(tooltip.value?.content).toBe('Second');
    });

    it('waits the full delay again when the pointer crosses into another card', () => {
        const first = document.createElement('div');
        const second = document.createElement('div');
        first.setAttribute('data-tooltip-group', '');
        second.setAttribute('data-tooltip-group', '');
        document.body.append(first, second);

        showTooltip(trigger(first), 'First');
        vi.advanceTimersByTime(400);
        hideTooltip();

        showTooltip(trigger(second), 'Second');
        vi.advanceTimersByTime(0);
        expect(tooltip.value).toBeNull();
    });

    it('closes with its own trigger going away, shown or still waiting, and never with another', () => {
        const hovered = trigger();
        const neighbour = trigger();

        showTooltip(hovered, 'Mine');
        hideTooltipOf(neighbour);
        vi.advanceTimersByTime(400);
        expect(tooltip.value?.content).toBe('Mine');

        hideTooltipOf(neighbour);
        expect(tooltip.value?.content).toBe('Mine');

        hideTooltipOf(hovered);
        expect(tooltip.value).toBeNull();

        showTooltip(hovered, 'Again');
        hideTooltipOf(hovered);
        vi.advanceTimersByTime(1000);
        expect(tooltip.value).toBeNull();
    });

    it('shows a tap hint at once and closes on hide', () => {
        showTooltipImmediately(trigger(), 'Tap');
        expect(tooltip.value?.content).toBe('Tap');

        hideTooltip();
        expect(tooltip.value).toBeNull();
    });
});
