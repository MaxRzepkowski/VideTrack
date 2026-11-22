import { jsonFetch } from '@/lib/http';
import { viewerUsername } from '@/lib/pageContext';
import { listUrl } from '@/lib/urls';
import { writeLibrary, writeLibraryAndLeave } from '@/stores/library';
import { router } from '@inertiajs/vue3';

/** What a list's own form sends: its name, its line and who may see it. */
export interface ListFields {
    title: string | null;
    description: string | null;
    is_public: boolean;
}

/**
 * The lists themselves, written through API v1 like every write of the
 * library: one write at a time, the page's own props reloaded after it, and a
 * refusal told in the server's words. A write that makes a list goes to it;
 * deleting one goes to the viewer's lists, without reading the gone list again.
 */
export function useListActions() {
    /** A write that answers a new list, and the list's id once it landed. */
    async function made(send: () => Promise<Response>): Promise<number | null> {
        let id: number | null = null;

        const landed = await writeLibrary(async () => {
            const response = await send();

            if (response.ok) {
                id = ((await response.clone().json()) as { data: { id: number } }).data.id;
            }

            return response;
        });

        return landed ? id : null;
    }

    async function goToMade(send: () => Promise<Response>): Promise<boolean> {
        const id = await made(send);

        if (id !== null) {
            // A list made here is the viewer's own, so it lives under them.
            router.visit(listUrl(viewerUsername(), id));
        }

        return id !== null;
    }

    return {
        create: (title: string): Promise<boolean> => goToMade(() => jsonFetch(route('ui.lists.store'), { method: 'POST', body: { title } })),

        duplicate: (list: number): Promise<boolean> => goToMade(() => jsonFetch(route('ui.lists.duplicate', { list }), { method: 'POST' })),

        update: (list: number, fields: ListFields): Promise<boolean> =>
            writeLibrary(() => jsonFetch(route('ui.lists.update', { list }), { method: 'PATCH', body: fields }), ['list']),

        destroy: (list: number): Promise<boolean> =>
            writeLibraryAndLeave(
                () => jsonFetch(route('ui.lists.destroy', { list }), { method: 'DELETE' }),
                route('users.lists', { user: viewerUsername() }),
            ),

        removeItem: (list: number, item: number): Promise<boolean> =>
            writeLibrary(() => jsonFetch(route('ui.lists.items.destroy', { list, item }), { method: 'DELETE' })),

        reorder: (listIds: number[]): Promise<boolean> =>
            writeLibrary(() => jsonFetch(route('ui.lists.reorder'), { method: 'PUT', body: { list_ids: listIds } }), ['lists']),

        reorderItems: (list: number, itemIds: number[]): Promise<boolean> =>
            writeLibrary(() => jsonFetch(route('ui.lists.items.reorder', { list }), { method: 'PUT', body: { item_ids: itemIds } })),
    };
}
