import Pagination from '@/components/Pagination.vue';
import { vTooltip } from '@/directives/tooltip';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function pager(currentPage: number, totalPages: number) {
    return mount(Pagination, { props: { currentPage, totalPages }, global: { directives: { tooltip: vTooltip } } });
}

/** What the pager reads like: page numbers, `…` for a gap. */
const labels = (wrapper: ReturnType<typeof pager>) =>
    wrapper
        .findAll('button:not([aria-label]), span')
        .map((element) => element.text())
        .filter((text) => text !== '');

describe('Pagination', () => {
    it('is not drawn for a single page', () => {
        expect(pager(1, 1).find('nav').exists()).toBe(false);
    });

    it('lists every page of a short run', () => {
        expect(labels(pager(2, 5))).toEqual(['1', '2', '3', '4', '5']);
    });

    it('keeps the first, the last and a window around the current page of a long run', () => {
        expect(labels(pager(1, 20))).toEqual(['1', '2', '…', '20']);
        expect(labels(pager(10, 20))).toEqual(['1', '…', '9', '10', '11', '…', '20']);
        expect(labels(pager(20, 20))).toEqual(['1', '…', '19', '20']);
    });

    it('asks for a neighbouring page, never the current one or one past the ends', async () => {
        const wrapper = pager(1, 3);

        await wrapper.find('button[aria-label="Previous"]').trigger('click');
        await wrapper.find('button[aria-current="page"]').trigger('click');
        await wrapper.find('button[aria-label="Next"]').trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === '3')
            ?.trigger('click');

        expect(wrapper.emitted('goto')).toEqual([[2], [3]]);
    });
});
