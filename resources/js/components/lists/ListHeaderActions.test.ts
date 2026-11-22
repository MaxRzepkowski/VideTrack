import ListHeaderActions from '@/components/lists/ListHeaderActions.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { FollowMode } from '@/types/generated';
import type { ListDetailsData, ListItemEntry } from '@/types/media';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const followCollection = vi.fn<(provider: string, ref: string, mode: FollowMode | null, reload: string[]) => Promise<boolean>>(() =>
    Promise.resolve(true),
);

vi.mock('@/composables/useCreatorActions', () => ({
    useCreatorActions: () => ({ followCollection }),
}));

function collectionList(followMode: FollowMode | null): ListDetailsData {
    return {
        id: 12,
        title: 'The Lord of the Rings Collection',
        description: null,
        systemKey: null,
        displayTitle: 'The Lord of the Rings Collection',
        collection: { provider: 'tmdb', ref: '119' },
        isPublic: true,
        backdropPath: null,
        backdrops: [],
        posters: [],
        owner: null,
        isOwner: false,
        canDelete: false,
        followMode,
        collectionRelations: null,
    };
}

function bell(followMode: FollowMode | null) {
    const wrapper = mount(ListHeaderActions, {
        props: { list: collectionList(followMode), itemCount: 3, editing: false, positions: [] },
        global: { directives: { tooltip: vTooltip } },
    });

    return wrapper.findAll('button').find((button) => button.attributes('aria-pressed') !== undefined);
}

beforeEach(() => {
    followCollection.mockClear();
    setPageProps({});
});

describe('the collection bell', () => {
    it('walks off → following → off, like a title the user follows by hand', async () => {
        await bell(null)?.trigger('click');
        await flushPromises();
        expect(followCollection).toHaveBeenLastCalledWith('tmdb', '119', 'follow', ['list']);

        await bell('follow')?.trigger('click');
        await flushPromises();
        expect(followCollection).toHaveBeenLastCalledWith('tmdb', '119', null, ['list']);
    });

    it('takes a muted bell back to off', async () => {
        await bell('mute')?.trigger('click');
        await flushPromises();
        expect(followCollection).toHaveBeenLastCalledWith('tmdb', '119', null, ['list']);
    });
});

describe('the list menu', () => {
    // The menu as plain markup: what is in it, not how reka-ui opens it.
    const menuStubs = {
        DropdownMenu: { template: '<div><slot /></div>' },
        DropdownMenuTrigger: { template: '<div><slot /></div>' },
        DropdownMenuContent: { template: '<div><slot /></div>' },
        DropdownMenuSeparator: true,
        DropdownMenuItem: { template: '<button type="button" role="menuitem"><slot /></button>' },
    };

    const position = (title: string, year: number, ref: string, imdbId: string, mediaItemId: number): ListItemEntry => ({
        id: mediaItemId,
        addedAt: null,
        genres: [],
        mediaItemId,
        imdbId,
        media: mediaSummary({ title, year, ref }),
    });

    function openMenu(
        positions = [position('The Fellowship of the Ring', 2001, '120', 'tt0120737', 8), position('The Two Towers', 2002, '121', 'tt0167261', 9)],
    ) {
        return mount(ListHeaderActions, {
            props: { list: collectionList(null), itemCount: 3, editing: false, positions },
            global: { directives: { tooltip: vTooltip }, stubs: menuStubs },
        });
    }

    function menuItem(wrapper: ReturnType<typeof openMenu>, label: string) {
        return wrapper.findAll('[role="menuitem"]').find((item) => item.text() === label);
    }

    it('downloads what the list shows, a title per line, named after the list', async () => {
        const blobs: Blob[] = [];
        const names: string[] = [];
        vi.spyOn(URL, 'createObjectURL').mockImplementation((blob) => {
            blobs.push(blob as Blob);

            return 'blob:list';
        });
        vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            names.push(this.download);
        });

        await menuItem(openMenu(), 'Download as .txt')?.trigger('click');

        expect(names).toEqual(['The Lord of the Rings Collection.txt']);
        expect(await blobs[0]?.text()).toBe('The Fellowship of the Ring (2001)\nThe Two Towers (2002)\n');
        expect(blobs[0]?.type).toBe('text/plain;charset=utf-8');

        vi.restoreAllMocks();
    });

    it('downloads the same positions as a spreadsheet, with their ids', async () => {
        const blobs: Blob[] = [];
        const names: string[] = [];
        vi.spyOn(URL, 'createObjectURL').mockImplementation((blob) => {
            blobs.push(blob as Blob);

            return 'blob:list';
        });
        vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            names.push(this.download);
        });

        await menuItem(openMenu(), 'Download as .csv')?.trigger('click');

        expect(names).toEqual(['The Lord of the Rings Collection.csv']);
        expect((await blobs[0]?.text())?.split('\r\n').slice(1, 3)).toEqual([
            'The Fellowship of the Ring,2001,movie,tmdb,120,tt0120737,8',
            'The Two Towers,2002,movie,tmdb,121,tt0167261,9',
        ]);
        expect(blobs[0]?.type).toBe('text/csv;charset=utf-8');

        vi.restoreAllMocks();
    });

    it('is not offered when the list shows nothing', async () => {
        const wrapper = openMenu([]);

        expect(menuItem(wrapper, 'Duplicate list')).toBeDefined();
        expect(menuItem(wrapper, 'Download as .txt')).toBeUndefined();
        expect(menuItem(wrapper, 'Download as .csv')).toBeUndefined();
    });

    it("leaves a collection's refresh to its details window", () => {
        const wrapper = openMenu();

        expect(menuItem(wrapper, 'Duplicate list')).toBeDefined();
        expect(menuItem(wrapper, 'Refresh from provider')).toBeUndefined();
    });
});
