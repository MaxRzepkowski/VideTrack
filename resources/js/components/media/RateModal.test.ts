import RateModal from '@/components/media/RateModal.vue';
import { useMediaModals } from '@/composables/useMediaModals';
import { mediaSummary, userState } from '@/test/factories';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const rated: { rating: number | null } = { rating: null };
const rate = vi.fn();

vi.mock('@/composables/useMediaActions', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/composables/useMediaActions')>()),
    useMediaActions: () => ({ rate }),
    shownState: () => userState({ rating: rated.rating }),
}));

const DUNE = mediaSummary({ title: 'Dune' });

function modal(rating: number | null) {
    rated.rating = rating;
    useMediaModals().openRate(DUNE);

    return mount(RateModal, { attachTo: document.body });
}

const stars = () => [...document.querySelectorAll<HTMLButtonElement>('[role=dialog] button[aria-pressed]')];
const dialogText = () => document.querySelector('[role=dialog]')?.textContent ?? '';

beforeEach(() => rate.mockClear());
afterEach(() => useMediaModals().close());

describe('RateModal', () => {
    it('offers ten stars to a title not rated yet, and rates and closes on one', async () => {
        const wrapper = modal(null);
        await flushPromises();

        expect(dialogText()).toContain('Not rated');
        expect(stars()).toHaveLength(10);
        expect(dialogText()).not.toContain('Remove rating');

        stars()[7]!.click();
        await flushPromises();

        expect(rate).toHaveBeenCalledWith(DUNE, 8);
        expect(useMediaModals().modals.rate).toBeNull();

        wrapper.unmount();
    });

    it('says the rating given, marks its star and offers to remove it', async () => {
        const wrapper = modal(9);
        await flushPromises();

        expect(dialogText()).toContain('Your rating');
        expect(dialogText()).toContain('9/10');
        expect(stars()[8]!.getAttribute('aria-pressed')).toBe('true');

        [...document.querySelectorAll<HTMLButtonElement>('[role=dialog] button')]
            .find((button) => button.textContent?.includes('Remove rating'))!
            .click();
        await flushPromises();

        expect(rate).toHaveBeenCalledWith(DUNE, null);

        wrapper.unmount();
    });
});
