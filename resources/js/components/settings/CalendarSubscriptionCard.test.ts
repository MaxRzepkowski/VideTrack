import CalendarSubscriptionCard from '@/components/settings/CalendarSubscriptionCard.vue';
import { confirm } from '@/composables/useConfirm';
import { useMediaTypes } from '@/test/factories';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/composables/useConfirm', () => ({ confirm: vi.fn(() => Promise.resolve(true)) }));

const calendarFeed = { url: 'https://example.com/calendar/abc.ics', defaultScope: 'all' } as const;
const rssFeed = { url: 'https://example.com/calendar/abc.rss', defaultScope: 'premieres' } as const;

beforeEach(() => {
    useMediaTypes();
    vi.mocked(confirm).mockClear();
});

function card() {
    return mount(CalendarSubscriptionCard, { props: { calendarFeed, rssFeed } });
}

const address = (wrapper: ReturnType<typeof card>, id: string) => (wrapper.get(`#${id}`).element as HTMLInputElement).value;

describe('CalendarSubscriptionCard', () => {
    it('starts on the plain address of each feed, in the scope each carries by default', () => {
        const wrapper = card();

        expect(address(wrapper, 'calendar-feed-url')).toBe(calendarFeed.url);
        expect(address(wrapper, 'calendar-rss-url')).toBe(rssFeed.url);
        expect((wrapper.get('#calendar-feed-scope').element as HTMLSelectElement).value).toBe('all');
        expect((wrapper.get('#calendar-rss-scope').element as HTMLSelectElement).value).toBe('premieres');
        expect(wrapper.get('a[href^="webcal:"]').attributes('href')).toBe('webcal://example.com/calendar/abc.ics');
    });

    it('narrows both addresses by the types, each by its own scope', async () => {
        const wrapper = card();
        const boxes = wrapper.findAll('input[type="checkbox"]');

        for (const box of boxes.slice(1)) {
            await box.setValue(false);
        }
        await wrapper.get('#calendar-feed-scope').setValue('premieres');

        const first = (boxes[0]!.element as HTMLInputElement).value;

        expect(address(wrapper, 'calendar-feed-url')).toBe(`${calendarFeed.url}?types=${first}&scope=premieres`);
        expect(address(wrapper, 'calendar-rss-url')).toBe(`${rssFeed.url}?types=${first}`);

        await wrapper.get('#calendar-rss-scope').setValue('all');
        expect(address(wrapper, 'calendar-rss-url')).toBe(`${rssFeed.url}?types=${first}&scope=all`);
        expect(wrapper.get('a[href^="webcal:"]').attributes('href')).toBe(`webcal://example.com/calendar/abc.ics?types=${first}&scope=premieres`);
    });

    it('keeps the last type ticked, as a feed of no type would be empty', async () => {
        const wrapper = card();
        const boxes = wrapper.findAll('input[type="checkbox"]');

        for (const box of boxes.slice(1)) {
            await box.setValue(false);
        }

        expect(boxes[0]!.attributes('disabled')).toBeDefined();
        expect(boxes[1]!.attributes('disabled')).toBeUndefined();
    });

    it('replaces the address only once confirmed', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
        const wrapper = card();
        const replace = wrapper.findAll('button').find((button) => button.text() === 'Replace address')!;

        vi.mocked(confirm).mockResolvedValueOnce(false);
        await replace.trigger('click');
        await flushPromises();
        expect(post).not.toHaveBeenCalled();

        await replace.trigger('click');
        await flushPromises();
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenCalledWith('/settings.calendar-token.update', {}, { preserveScroll: true, preserveState: true });
    });
});
