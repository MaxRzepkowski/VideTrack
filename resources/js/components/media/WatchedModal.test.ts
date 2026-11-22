import WatchedModal from '@/components/media/WatchedModal.vue';
import { absorbUserStates } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { vTooltip } from '@/directives/tooltip';
import type { JsonFetchOptions } from '@/lib/http';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import { nth } from '@/test/lists';
import { setPageProps } from '@/test/page';
import type { UserMediaState } from '@/types/generated';
import type { MediaSummary } from '@/types/media';
import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type Call = { url: string; method: string; body: unknown };

const calls: Call[] = [];
const history: { plays: unknown[]; cycles: unknown[] } = { plays: [], cycles: [] };

// Reads answer with the history above; every write lands.
vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, { method = 'GET', body }: JsonFetchOptions = {}) => {
        calls.push({ url, method, body });

        const data = url.includes('plays.index') ? history.plays : url.includes('cycles.index') ? history.cycles : {};

        return Promise.resolve(new Response(JSON.stringify({ data, states: {} }), { status: 200 }));
    },
}));

const answerConfirm = vi.fn<(request: { message: string }) => Promise<boolean>>();

vi.mock('@/composables/useConfirm', () => ({ confirm: (request: { message: string }) => answerConfirm(request) }));

let wrapper: VueWrapper;

beforeEach(() => {
    vi.useFakeTimers({ now: new Date('2026-10-10T12:00:00Z'), toFake: ['Date'] });
    useMediaTypes();
    setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences: { timezone: 'UTC' } } } });
    calls.length = 0;
    history.plays = [];
    history.cycles = [];
    answerConfirm.mockResolvedValue(true);
    wrapper = mount(WatchedModal, { global: { directives: { tooltip: vTooltip } } });
});

afterEach(() => {
    useMediaModals().close();
    wrapper.unmount();
    vi.useRealTimers();
});

async function open(media: MediaSummary, state: Partial<UserMediaState> = {}, tab: 'watched' | 'dropped' = 'watched'): Promise<void> {
    absorbUserStates({ [`${media.type}:${media.ref}`]: userState(state) });
    useMediaModals().openWatched(media, tab);
    await flushPromises();
}

// The dialog renders through a portal, into the document body.
const screen = () => new DOMWrapper(document.body);

function button(text: string) {
    const found = screen()
        .findAll('button')
        .find((candidate) => candidate.text() === text);

    if (!found) {
        throw new Error(
            `No button "${text}" among: ${screen()
                .findAll('button')
                .map((candidate) => candidate.text())}`,
        );
    }

    return found;
}

/** A row under the one action, by its name: its line says what it does after it. */
function row(label: string) {
    const found = screen()
        .findAll('button')
        .find((candidate) => candidate.find('span.font-semibold').exists() && candidate.find('span.font-semibold').text() === label);

    if (!found) {
        throw new Error(`No row "${label}"`);
    }

    return found;
}

const writes = () => calls.filter((call) => call.method !== 'GET');

const movie = mediaSummary({ type: 'movie', ref: 'm1', title: 'Heat', releaseDate: '1995-12-15' });
const book = mediaSummary({ type: 'book', ref: 'b1', title: 'Dune', progressTotal: 400 });
const game = mediaSummary({ type: 'game', ref: 'g1', title: 'Hades' });
const show = mediaSummary({ type: 'show', ref: 's1', title: 'Severance' });

describe('completing', () => {
    it('marks a film watched now and closes', async () => {
        await open(movie);

        await button('Mark as watched').trigger('click');
        await flushPromises();

        expect(writes()).toEqual([
            {
                url: '/ui.media.plays.store/movie/m1',
                method: 'POST',
                body: { watched_at: 'now', amount: null, new_attempt: false, merge_hours: true },
            },
        ]);
        expect(useMediaModals().modals.watched).toBeNull();
    });

    it('dates the play to the release', async () => {
        await open(movie);

        // The action's right side picks when.
        await screen().find('button[aria-label="Change the date"]').trigger('keydown', { key: 'Enter' });
        await flushPromises();
        await screen()
            .findAll('[role=menuitem]')
            .find((item) => item.text() === 'Release date')!
            .trigger('click');
        await flushPromises();

        expect(screen().find('button[aria-label="Change the date"]').text()).toBe('Dec 15');

        await button('Mark as watched').trigger('click');

        expect(nth(writes(), 0).body).toMatchObject({ watched_at: 'release' });
    });

    it('starts an untouched show on its first episode', async () => {
        await open(show);

        await button('Watch the first episode').trigger('click');

        expect(writes()[0]).toMatchObject({ url: '/ui.media.plays.store/show/s1', body: { watched_at: 'now', next_episode: true } });
    });
});

describe('the counter', () => {
    it('saves the page a book is on', async () => {
        await open(book, { status: 'in_progress', progress: 10 });

        await screen().find('input[type="number"]').setValue('120');
        await button('Save progress').trigger('click');

        expect(writes()).toEqual([{ url: '/ui.media.progress.update/book/b1', method: 'PUT', body: { set: 120 } }]);
    });

    it('takes a page typed as a percent of the book', async () => {
        await open(book, { status: 'in_progress', progress: 10 });

        await button('Percent').trigger('click');
        await screen().find('input[type="number"]').setValue('50');
        await button('Save progress').trigger('click');

        expect(nth(writes(), 0).body).toEqual({ set: 200 });
    });

    it("adds a game session's hours", async () => {
        await open(game, { status: 'in_progress', progress: 60 });

        expect(screen().find('label').text()).toBe('Hours in this session');

        await screen().find('input[type="number"]').setValue('1.5');
        await button('Add session').trigger('click');

        expect(nth(writes(), 0).body).toEqual({ add: 90 });
    });
});

describe('the steps', () => {
    it('opens a step from its row, names it, and comes back without writing', async () => {
        await open(game, { status: 'in_progress', progress: 60 });

        await row('Drop').trigger('click');

        expect(screen().text()).toContain('Drop this title');
        expect(screen().find('button[aria-label="Back"]').exists()).toBe(true);

        await screen().find('button[aria-label="Back"]').trigger('click');

        expect(button('Add session').exists()).toBe(true);
        expect(writes()).toEqual([]);
    });

    it("corrects a game's total in a step of its own, overwriting it", async () => {
        await open(game, { status: 'in_progress', progress: 60 });

        await row('Correct the total').trigger('click');
        await screen().find('input[type="number"]').setValue('2');
        await button('Save').trigger('click');

        expect(writes()).toEqual([{ url: '/ui.media.progress.update/game/g1', method: 'PUT', body: { set: 120 } }]);
    });
});

describe('what asks first', () => {
    it('throws the open attempt away only once confirmed', async () => {
        await open(game, { status: 'in_progress', progress: 60 });

        answerConfirm.mockResolvedValueOnce(false);
        await row('Remove progress').trigger('click');
        await flushPromises();

        expect(writes()).toEqual([]);

        await row('Remove progress').trigger('click');
        await flushPromises();

        expect(writes()[0]).toMatchObject({ url: '/ui.media.status.update/game/g1', body: { discard_progress: true } });
    });

    it('marks a show unwatched only once confirmed, saying how many episodes lose their plays', async () => {
        await open(show, { watched: true, watchedEpisodes: 9, airedEpisodes: 9, plays: 9, cycle: 1 });

        answerConfirm.mockResolvedValueOnce(false);
        await row('Mark as unwatched').trigger('click');
        await flushPromises();

        expect(answerConfirm).toHaveBeenCalledWith(expect.objectContaining({ message: 'This deletes the plays of 9 episodes from your history.' }));
        expect(writes()).toEqual([]);

        await row('Mark as unwatched').trigger('click');
        await flushPromises();

        expect(writes()).toEqual([{ url: '/ui.media.plays.destroy/show/s1', method: 'DELETE', body: undefined }]);
    });

    it('removes a history entry once confirmed, and reads the history again', async () => {
        history.plays = [
            { id: 7, watchedAt: '2026-10-01T20:00:00Z', outcome: 'completed', amount: null, cycle: 1 },
            { id: 8, watchedAt: '2026-09-01T20:00:00Z', outcome: 'completed', amount: null, cycle: 1 },
        ];
        await open(movie, { watched: true, plays: 2 });

        await row('History').trigger('click');
        await screen().find('button[aria-label="Remove this play"]').trigger('click');
        await flushPromises();

        expect(writes()).toEqual([{ url: '/ui.plays.destroy/7', method: 'DELETE', body: undefined }]);
        expect(calls.filter((call) => call.url.includes('plays.index'))).toHaveLength(2);
        expect(useMediaModals().modals.watched).not.toBeNull();
    });
});

describe('the history', () => {
    it('closes once its last entry is removed', async () => {
        history.plays = [{ id: 7, watchedAt: '2026-10-01T20:00:00Z', outcome: 'completed', amount: null, cycle: 1 }];
        await open(movie, { watched: true, plays: 1 });

        await row('History').trigger('click');
        await screen().find('button[aria-label="Remove this play"]').trigger('click');
        await flushPromises();

        expect(useMediaModals().modals.watched).toBeNull();
    });

    it('undoes the newest reset of a show once confirmed, saying how many plays go', async () => {
        const cycle = { startedAt: null, watchedEpisodes: 3, airedEpisodes: 9, completed: false, firstPlayAt: null, lastPlayAt: null };

        history.cycles = [
            { ...cycle, number: 2, plays: 3, isActive: true, canCancel: true },
            { ...cycle, number: 1, plays: 9, isActive: false, canCancel: false },
        ];
        await open(show, { watchedEpisodes: 3, airedEpisodes: 9, plays: 12, cycle: 2 });

        await row('Watch cycles').trigger('click');
        await row('Undo the reset').trigger('click');
        await flushPromises();

        expect(answerConfirm).toHaveBeenCalledWith(
            expect.objectContaining({ message: 'This deletes 3 plays of this cycle; the previous one stays untouched.' }),
        );
        expect(writes()).toEqual([{ url: '/ui.media.cycles.destroy/show/s1', method: 'DELETE', body: undefined }]);
        expect(useMediaModals().modals.watched).toBeNull();
    });
});

describe('a status already on', () => {
    it('opens on taking it off: resuming a hold, removing a drop', async () => {
        await open(movie, { status: 'on_hold' });

        await button('Resume').trigger('click');

        expect(writes()[0]).toMatchObject({ url: '/ui.media.status.update/movie/m1', body: { status: null } });

        await open(mediaSummary({ type: 'movie', ref: 'm2', title: 'Ronin' }), { status: 'dropped' });
        await button('Remove status').trigger('click');

        expect(writes()[1]).toMatchObject({ url: '/ui.media.status.update/movie/m2', body: { status: null } });
    });

    it('drops with the moment it happened', async () => {
        await open(movie, {}, 'dropped');

        await button('Drop this title').trigger('click');

        expect(writes()[0]).toMatchObject({ body: { status: 'dropped', amount: null, dropped_at: 'now' } });
    });
});
