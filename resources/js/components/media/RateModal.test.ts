import RateModal from '@/components/media/RateModal.vue';
import { useMediaModals } from '@/composables/useMediaModals';
import { RATING_MAX } from '@/lib/ratings';
import { mediaSummary, userState } from '@/test/factories';
import { stubMediaQueries } from '@/test/media';
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
afterEach(() => {
    useMediaModals().close();
    vi.unstubAllGlobals();
});

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

    it('says the score under the mouse between the ends of the scale, and nothing once it leaves', async () => {
        const wrapper = modal(null);
        await flushPromises();

        const scale = () => [...(document.querySelector('[role=dialog] .grid')?.children ?? [])].map((end) => end.textContent?.trim());

        expect(scale()).toEqual(['1', '', String(RATING_MAX)]);

        vi.setSystemTime(Date.now() + 1000);
        stars()[5]!.dispatchEvent(new PointerEvent('pointerenter', { pointerType: 'mouse' }));
        await flushPromises();

        expect(scale()).toEqual(['1', `6/${RATING_MAX}`, String(RATING_MAX)]);

        stars()[0]!.parentElement!.dispatchEvent(new MouseEvent('mouseleave'));
        await flushPromises();

        expect(scale()).toEqual(['1', '', String(RATING_MAX)]);

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

    it('only chooses with a finger, tapped or slid, and rates on save', async () => {
        stubMediaQueries((query) => query === '(any-pointer: coarse)');
        const wrapper = modal(null);
        await flushPromises();

        const save = () =>
            [...document.querySelectorAll<HTMLButtonElement>('[role=dialog] button')].find((button) => button.textContent?.includes('Save rating'))!;
        const row = stars()[0]!.parentElement!;
        const finger = (type: string, x: number, init: PointerEventInit = {}) =>
            new PointerEvent(type, { pointerType: 'touch', clientX: x, buttons: 1, bubbles: true, ...init });

        expect(save().disabled).toBe(true);

        // A tap: the browser's mouse events after it preview nothing.
        stars()[6]!.dispatchEvent(finger('pointerenter', 0, { pointerType: 'mouse' }));
        stars()[6]!.dispatchEvent(new PointerEvent('click', { pointerType: 'touch', bubbles: true }));
        await flushPromises();

        expect(rate).not.toHaveBeenCalled();
        expect(save().textContent).toContain(`Save rating 7/${RATING_MAX}`);

        // A slide: the star under the finger is the choice.
        row.getBoundingClientRect = () => ({ left: 0, width: 300 }) as DOMRect;
        vi.setSystemTime(Date.now() + 1000);
        row.dispatchEvent(finger('pointerdown', 10));
        row.dispatchEvent(finger('pointermove', 95));
        await flushPromises();

        expect(stars()[3]!.getAttribute('aria-pressed')).toBe('true');
        expect(save().textContent).toContain(`Save rating 4/${RATING_MAX}`);

        save().click();
        await flushPromises();

        expect(rate).toHaveBeenCalledWith(DUNE, 4);

        wrapper.unmount();
    });
});
