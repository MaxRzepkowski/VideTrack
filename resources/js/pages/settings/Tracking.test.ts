import { trackingSources } from '@/lib/tracking';
import Tracking from '@/pages/settings/Tracking.vue';
import { typePanels, useMediaTypes, userPreferences } from '@/test/factories';
import { setPageProps, testPage } from '@/test/page';
import type { UserPreferences } from '@/types';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { toast } from 'vue-sonner';

/** How many rows the matrix has: the summary counts own sources out of it. */
const TOTAL = trackingSources().length;

function page(url = '/settings/tracking', preferences: Partial<UserPreferences> = {}) {
    testPage.url = url;
    setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences: userPreferences(preferences), password_sign_in: true } } });

    useMediaTypes();

    return mount(Tracking, {
        props: { types: typePanels() },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
    });
}

const shown = (wrapper: ReturnType<typeof page>, name: string) =>
    (wrapper.get(`#tracking-panel-${name}`).element as HTMLElement).style.display !== 'none';

describe('Tracking', () => {
    it('opens on the calendar and countdowns, or on the tab its address names', () => {
        expect(shown(page(), 'views')).toBe(true);

        const linked = page('/settings/tracking?tab=notifications');

        expect(shown(linked, 'notifications')).toBe(true);
        expect(shown(linked, 'views')).toBe(false);
        expect(linked.get('#tracking-tab-notifications').attributes('aria-selected')).toBe('true');
        expect(shown(page('/settings/tracking?tab=elsewhere'), 'views')).toBe(true);
    });

    it('moves along the tabs with the arrow keys, the open one being the only stop', async () => {
        const wrapper = page();

        await wrapper.get('#tracking-tab-views').trigger('keydown', { key: 'ArrowLeft' });

        expect(shown(wrapper, 'creators')).toBe(true);
        expect(wrapper.get('#tracking-tab-creators').attributes('tabindex')).toBe('0');
        expect(wrapper.get('#tracking-tab-views').attributes('tabindex')).toBe('-1');

        await wrapper.get('#tracking-tab-creators').trigger('keydown', { key: 'Home' });

        expect(shown(wrapper, 'views')).toBe(true);
    });

    it('keeps the open tab in the address without a visit', async () => {
        const replace = vi.spyOn(window.history, 'replaceState');
        const wrapper = page();

        await wrapper.get('#tracking-tab-creators').trigger('click');

        expect(shown(wrapper, 'creators')).toBe(true);
        expect(String(replace.mock.lastCall?.[2])).toContain('tab=creators');
    });

    it('lists every kind of title with what it does on one line, and opens one onto its own settings', async () => {
        const wrapper = page();
        const movies = wrapper.get('#tracking-views-movie-row');

        expect(movies.text()).toContain('Movies');
        expect(movies.text()).toContain('same as default; cinema premiere and home release');
        expect(movies.findAll('[aria-hidden="true"].bg-line')).toHaveLength(0);
        expect(wrapper.find('#tracking-views-movie').exists()).toBe(false);

        await movies.trigger('click');

        expect(movies.attributes('aria-expanded')).toBe('true');
        expect(body(wrapper, 'views', 'movie').text()).toContain('Home release');
        expect(body(wrapper, 'views', 'movie').text()).toContain('Own sources');
        expect(cells(wrapper, 'views', 'movie')).toHaveLength(4);
    });

    it('opens every kind of title, also one with nothing but its sources to set', async () => {
        const wrapper = page();

        for (const panel of typePanels()) {
            await open(wrapper, 'views', panel.type);
            await open(wrapper, 'notifications', panel.type);

            expect(body(wrapper, 'views', panel.type).text()).toContain('Own sources');
            expect(body(wrapper, 'notifications', panel.type).text()).toContain('Own sources');
        }
    });

    it("lets a film's dates go one by one, the calendar's down to neither, and sends the mode each surface ends on", async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const wrapper = page();
        await open(wrapper, 'views', 'movie');

        expect(wrapper.text()).toContain('All changes saved');

        await box(wrapper, 'Movies – Calendar – Cinema premiere').trigger('change');

        expect(wrapper.text()).toContain('You have unsaved changes.');
        expect(row(wrapper, 'views', 'movie').text()).toContain('cinema premiere in the countdowns and home release');

        await box(wrapper, 'Movies – Calendar – Home release').trigger('change');

        expect(cells(wrapper, 'views', 'movie').map((box) => (box.element as HTMLInputElement).checked)).toEqual([false, true, false, true]);
        expect(cells(wrapper, 'views', 'movie').every((box) => box.attributes('disabled') === undefined)).toBe(true);
        expect(row(wrapper, 'views', 'movie').text()).toContain('cinema premiere in the countdowns and home release in the countdowns');

        await box(wrapper, 'Movies – Countdowns – Home release').trigger('change');
        await save(wrapper);

        expect(sent(visit)).toMatchObject({ calendar_release_mode: 'none', countdown_release_mode: 'cinema', notifications_release_mode: 'both' });
        expect(sent(visit)).not.toHaveProperty('options');
    });

    it('copies the defaults into a kind of title switched to its own sources, and keeps them its own when the defaults change', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const wrapper = page();
        await open(wrapper, 'views', 'book');

        expect(cells(wrapper, 'views', 'book')).toHaveLength(0);

        await ownSwitch(wrapper, 'views', 'book').setValue(true);

        // Six rows, two columns, ticked as the defaults are.
        expect(cells(wrapper, 'views', 'book').map((box) => (box.element as HTMLInputElement).checked)).toEqual([
            true,
            true,
            true,
            true,
            true,
            true,
            true,
            false,
            true,
            true,
            true,
            true,
        ]);
        expect(row(wrapper, 'views', 'book').text()).toContain(`own sources: calendar 6 of ${TOTAL}, countdowns 5 of ${TOTAL}`);

        await box(wrapper, 'Books – Calendar – Backlog').trigger('change');

        expect(row(wrapper, 'views', 'book').text()).toContain(`own sources: calendar 5 of ${TOTAL}, countdowns 5 of ${TOTAL}`);

        // The defaults take the dropped titles onto the countdowns; the books' own stay as they were.
        await box(wrapper, 'Countdowns – Dropped titles').trigger('change');

        expect(row(wrapper, 'views', 'book').text()).toContain(`own sources: calendar 5 of ${TOTAL}, countdowns 5 of ${TOTAL}`);
        expect(row(wrapper, 'views', 'manga').text()).toContain('same as default');

        await save(wrapper);

        expect(sent(visit)).toMatchObject({
            countdown_dropped: true,
            type_sources: {
                book: {
                    calendar: ['activity', 'on_hold', 'dropped', 'followed', 'creator'],
                    countdowns: ['activity', 'backlog', 'on_hold', 'followed', 'creator'],
                },
            },
        });
    });

    it("switches one tab's own sources off without touching the other's, and the kind of title goes once neither has any", async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const wrapper = page('/settings/tracking', {
            type_sources: { movie: { calendar: ['backlog'], countdowns: ['backlog'], notifications: ['followed'] } },
        });
        await open(wrapper, 'views', 'movie');
        await open(wrapper, 'notifications', 'movie');

        expect(row(wrapper, 'notifications', 'movie').text()).toContain(`own sources: 1 of ${TOTAL}; cinema premiere and home release`);
        expect(cells(wrapper, 'notifications', 'movie').map((box) => (box.element as HTMLInputElement).checked)).toEqual([
            false,
            false,
            false,
            false,
            true,
            false,
            true,
            true,
        ]);

        await ownSwitch(wrapper, 'views', 'movie').setValue(false);
        await save(wrapper);

        expect(row(wrapper, 'views', 'movie').text()).toContain('same as default');
        expect(sent(visit)?.type_sources).toEqual({ movie: { notifications: ['followed'] } });

        await ownSwitch(wrapper, 'notifications', 'movie').setValue(false);
        await save(wrapper);

        expect(sent(visit)?.type_sources).toEqual({});
    });

    it("turns a kind of title's notification sources and its events with switches, as the defaults are", async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const wrapper = page('/settings/tracking?tab=notifications');
        await open(wrapper, 'notifications', 'show');

        expect(cells(wrapper, 'notifications', 'show').every((box) => box.attributes('role') === 'switch')).toBe(true);

        await box(wrapper, 'Shows – Notifications – New episodes').setValue(false);
        await ownSwitch(wrapper, 'notifications', 'show').setValue(true);
        await box(wrapper, 'Shows – Notifications – On hold titles').setValue(true);

        expect(row(wrapper, 'notifications', 'show').text()).toContain(`own sources: 5 of ${TOTAL}; show status`);

        await save(wrapper);

        expect(sent(visit)).toMatchObject({
            notify_episodes: false,
            notify_show_status: true,
            type_sources: { show: { notifications: ['activity', 'backlog', 'on_hold', 'followed', 'creator'] } },
        });
    });

    it('counts every quick click, also those landing before the page is drawn again', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const wrapper = page();
        await open(wrapper, 'views', 'movie');
        await ownSwitch(wrapper, 'views', 'movie').setValue(true);

        // Every ticked countdowns box of the sources (the dropped titles are not), then both calendar dates, all in one go.
        const quick = [
            ...['In your history', 'Backlog', 'On hold titles', 'Followed manually', 'New titles from followed creators'].map(
                (source) => `Movies – Countdowns – ${source}`,
            ),
            'Movies – Calendar – Cinema premiere',
            'Movies – Calendar – Home release',
        ].map((label) => box(wrapper, label));
        for (const control of quick) {
            // Not awaited: the next lands before the row is drawn again.
            void control.trigger('change');
        }
        await nextTick();

        expect(row(wrapper, 'views', 'movie').text()).toContain(
            // The countdowns take no films now, and the calendar's dates are both off.
            `own sources: calendar 6 of ${TOTAL}, countdowns 0 of ${TOTAL}; off: cinema premiere and home release`,
        );

        await save(wrapper);

        expect(sent(visit)).toMatchObject({
            calendar_release_mode: 'none',
            type_sources: { movie: { calendar: ['activity', 'backlog', 'on_hold', 'dropped', 'followed', 'creator'], countdowns: [] } },
        });
    });

    it("holds a film's premiere ping off while premieres are off by default, and says why", async () => {
        const wrapper = page('/settings/tracking?tab=notifications', { notify_premieres: false });
        await open(wrapper, 'notifications', 'movie');
        const premiere = () => box(wrapper, 'Movies – Notifications – Cinema premiere');

        expect(premiere()?.attributes('disabled')).toBeDefined();
        expect(body(wrapper, 'notifications', 'movie').text()).toContain('Needs Premieres on under “By default”.');
        // Read as off, as it works, and tied to the note that says why.
        expect((premiere()?.element as HTMLInputElement).checked).toBe(false);
        expect(premiere()?.attributes('aria-describedby')).toBe('tracking-notifications-movie-options-premiere-note');
        expect(wrapper.find('#tracking-notifications-movie-options-premiere-note').text()).toBe('Needs Premieres on under “By default”.');
        expect(row(wrapper, 'notifications', 'movie').text()).toContain('same as default; home release');

        // Premieres back on in the defaults.
        await box(wrapper, 'Premieres').setValue(true);

        expect(premiere()?.attributes('disabled')).toBeUndefined();
        expect(body(wrapper, 'notifications', 'movie').text()).not.toContain('Needs Premieres');
        expect((premiere()?.element as HTMLInputElement).checked).toBe(true);
        expect(premiere()?.attributes('aria-describedby')).toBeUndefined();
        expect(row(wrapper, 'notifications', 'movie').text()).toContain('same as default; cinema premiere and home release');
    });

    it("switches a game's events off in one view and sends it", async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const wrapper = page();
        await open(wrapper, 'views', 'game');

        await box(wrapper, 'Games – Countdowns – Events and showcases').trigger('change');

        expect(row(wrapper, 'views', 'game').text()).toContain(
            'same as default; every platform on its own in the calendar, DLC and expansions, remakes, remasters, and events in the calendar',
        );

        await save(wrapper);

        expect(sent(visit)).toMatchObject({ calendar_events: true, countdown_events: false });
    });

    it('keeps only the own sources of the kinds of title it offers, so a switched-off module never blocks a save', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const wrapper = page('/settings/tracking', { type_sources: { movie: { notifications: ['backlog'] }, boardgame: { notifications: [] } } });

        await box(wrapper, 'Calendar – In your history').trigger('change');
        await save(wrapper);

        expect(sent(visit)?.type_sources).toEqual({ movie: { notifications: ['backlog'] } });
    });

    it('says when a save is refused, rather than leave the bar waiting', async () => {
        const error = vi.spyOn(toast, 'error');
        vi.spyOn(router, 'visit').mockImplementation((_url, options) => options?.onError?.({ type_sources: 'Refused.' }));
        const wrapper = page();

        await box(wrapper, 'Calendar – In your history').trigger('change');
        await save(wrapper);

        expect(error).toHaveBeenCalledWith('Something went wrong. Please try again.');
    });

    it('greys out the dates of a view the type takes no titles on, and says why', async () => {
        const wrapper = page('/settings/tracking', { type_sources: { movie: { calendar: [], countdowns: ['backlog'] } } });
        await open(wrapper, 'views', 'movie');
        const premiere = (view: string) => box(wrapper, `Movies – ${view} – Cinema premiere`);

        expect(premiere('Calendar').attributes('disabled')).toBeDefined();
        expect((premiere('Calendar').element as HTMLInputElement).checked).toBe(false);
        expect(premiere('Calendar').attributes('aria-describedby')).toBe('tracking-views-movie-options-premiere-note');
        expect(premiere('Countdowns').attributes('disabled')).toBeUndefined();
        expect(body(wrapper, 'views', 'movie').text()).toContain('Greyed out where this type takes no titles.');

        // The calendar takes the backlog again: its dates are back as they were chosen.
        await box(wrapper, 'Movies – Calendar – Backlog').trigger('change');

        expect(premiere('Calendar').attributes('disabled')).toBeUndefined();
        expect((premiere('Calendar').element as HTMLInputElement).checked).toBe(true);
        expect(body(wrapper, 'views', 'movie').text()).not.toContain('Greyed out');
    });

    it('discards every kind of change back to what was saved', async () => {
        const wrapper = page();
        await open(wrapper, 'views', 'book');
        await open(wrapper, 'views', 'movie');
        await ownSwitch(wrapper, 'views', 'book').setValue(true);
        await box(wrapper, 'Movies – Calendar – Cinema premiere').trigger('change');
        await box(wrapper, 'Calendar – In your history').trigger('change');

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Discard')
            ?.trigger('click');

        expect(wrapper.text()).toContain('All changes saved');
        expect(row(wrapper, 'views', 'book').text()).toContain('same as default');
        expect(row(wrapper, 'views', 'movie').text()).toContain('same as default; cinema premiere and home release');
        expect((box(wrapper, 'Calendar – In your history').element as HTMLInputElement).checked).toBe(true);
    });
});

type Wrapper = ReturnType<typeof page>;

const row = (wrapper: Wrapper, tab: string, type: string) => wrapper.get(`#tracking-${tab}-${type}-row`);
const body = (wrapper: Wrapper, tab: string, type: string) => wrapper.get(`#tracking-${tab}-${type}`);
/** The boxes in the open kind of title's tables, row by row: its sources when its own, then its options. */
const cells = (wrapper: Wrapper, tab: string, type: string) => body(wrapper, tab, type).findAll('table input');
/** A control by the name a screen reader gives it. */
const box = (wrapper: Wrapper, label: string) => wrapper.get(`input[aria-label="${label}"]`);
const ownSwitch = (wrapper: Wrapper, tab: string, type: string) => body(wrapper, tab, type).get(':scope > div:first-child input[role="switch"]');

async function open(wrapper: Wrapper, tab: string, type: string): Promise<void> {
    await row(wrapper, tab, type).trigger('click');
}

async function save(wrapper: Wrapper): Promise<void> {
    await wrapper.get('form').trigger('submit');
}

/** What the last save sent. */
const sent = (visit: { mock: { lastCall?: unknown[] } }): Record<string, unknown> | undefined => {
    const options = visit.mock.lastCall?.[1];

    return typeof options === 'object' && options !== null && 'data' in options ? (options.data as Record<string, unknown>) : undefined;
};
