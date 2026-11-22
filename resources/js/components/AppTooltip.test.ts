import AppTooltip from '@/components/AppTooltip.vue';
import { hideTooltip, tooltip } from '@/lib/tooltip';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

function show(content: string | { label: string; value: string }[]): void {
    tooltip.value = { content, rect: new DOMRect(10, 10, 40, 20), trigger: document.body };
}

afterEach(() => {
    hideTooltip();
    document.body.innerHTML = '';
});

describe('AppTooltip', () => {
    it('breaks a sentence where its text breaks: what happened, then what a click does', async () => {
        mount(AppTooltip, { attachTo: document.body });
        show('Watched March 12, 2026\nChange status');
        await flushPromises();

        const bubble = document.querySelector('[role="tooltip"]');

        expect(bubble?.textContent).toContain('Watched March 12, 2026\nChange status');
        expect(bubble?.classList).toContain('whitespace-pre-line');
    });

    it('lays rows out as a table, not as broken lines', async () => {
        mount(AppTooltip, { attachTo: document.body });
        show([{ label: 'Watched', value: '3 h' }]);
        await flushPromises();

        const bubble = document.querySelector('[role="tooltip"]');

        expect(bubble?.classList).not.toContain('whitespace-pre-line');
        expect(bubble?.textContent).toContain('Watched');
        expect(bubble?.textContent).toContain('3 h');
    });
});
