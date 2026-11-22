import type { Creator, CreatorStatesMap } from '@/lib/creators';
import { jsonFetch } from '@/lib/http';
import { absorbCreatorStates, creatorState, writeCreator, writeLibrary } from '@/stores/library';
import type { FollowMode } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

export type { Creator } from '@/lib/creators';

/** How API v1 names each kind of creator in its paths. */
const RESOURCE: Record<Creator['kind'], string> = { person: 'people', company: 'companies', network: 'networks' };

const path = (creator: Creator) => ({ provider: creator.provider, ref: creator.ref });

/**
 * The bell and the lists of a person, a company or a network — one call of
 * API v1 each — plus the bell of a provider collection. A creator's writes go
 * one after another, and a toggle reads the state it flips when its request
 * goes out: two quick clicks flip twice instead of sending one picture twice.
 */
export function useCreatorActions() {
    const followRequest = (creator: Creator, on: boolean): Promise<Response> =>
        jsonFetch(route(`ui.${RESOURCE[creator.kind]}.follow.update`, path(creator)), {
            method: 'PUT',
            body: { mode: on ? 'follow' : null },
        });

    const listsRequest = (creator: Creator, listIds: number[]): Promise<Response> =>
        jsonFetch(route(`ui.${RESOURCE[creator.kind]}.lists.update`, path(creator)), { method: 'PUT', body: { list_ids: listIds } });

    /** People and companies have no tracking matrix behind them: the bell is on or off. */
    const follow = (creator: Creator, on: boolean): Promise<boolean> => writeCreator(creator, () => followRequest(creator, on));

    const toggleFollow = (creator: Creator): Promise<boolean> => writeCreator(creator, () => followRequest(creator, !creatorState(creator).followed));

    /** A collection's bell has three states, like a title's: follow, mute, or back to the matrix. */
    const followCollection = (provider: string, ref: string, mode: FollowMode | null, reload: string[]): Promise<boolean> =>
        writeLibrary(() => jsonFetch(route('ui.collections.follow.update', { provider, ref }), { method: 'PUT', body: { mode } }), reload);

    /**
     * Takes in the lists the creator is on, whatever page its card sits on.
     * Answers whether they came; when they did not, the user is told.
     */
    const loadLists = async (creator: Creator): Promise<boolean> => {
        try {
            const response = await jsonFetch(route(`ui.${RESOURCE[creator.kind]}.lists.show`, path(creator)));

            if (response.ok) {
                absorbCreatorStates(((await response.json()) as { creatorStates: CreatorStatesMap }).creatorStates);

                return true;
            }
        } catch (error) {
            // The request never got an answer (offline, the server down).
            if (!(error instanceof TypeError)) {
                throw error;
            }
        }

        toast.error(trans('common.something_went_wrong_please_try_again'));

        return false;
    };

    /** Puts the creator on one list or takes it off; its other memberships stay. */
    // The lists' counts move with every tick, and only the server knows them, so a tick reloads them too.
    const toggleList = (creator: Creator, listId: number): Promise<boolean> =>
        writeCreator(creator, () => {
            const ids = creatorState(creator).listIds;

            return listsRequest(creator, ids.includes(listId) ? ids.filter((id) => id !== listId) : [...ids, listId]);
        }, ['userLists']);

    /** Quick-create from the lists modal: the list, then the creator on it. */
    const createListWith = (creator: Creator, title: string): Promise<boolean> =>
        writeCreator(creator, async () => {
            const created = await jsonFetch(route('ui.lists.store'), { method: 'POST', body: { title } });

            if (!created.ok) {
                return created;
            }

            const { data } = (await created.json()) as { data: { id: number } };

            return listsRequest(creator, [...creatorState(creator).listIds, data.id]);
        }, ['userLists']);

    return { follow, toggleFollow, followCollection, loadLists, toggleList, createListWith };
}
