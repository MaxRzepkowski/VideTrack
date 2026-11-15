import { useMediaModals } from '@/composables/useMediaModals';
import { mediaSummary } from '@/test/factories';
import { afterEach, describe, expect, it } from 'vitest';

afterEach(() => useMediaModals().close());

describe('useMediaModals', () => {
    it('opens the watched modal on the asked tab, for every caller at once', () => {
        const movie = mediaSummary();
        useMediaModals().openWatched(movie, 'dropped');

        const { modals } = useMediaModals();
        expect(modals.watched).toStrictEqual(movie);
        expect(modals.watchedTab).toBe('dropped');
    });

    it('opens on the watched tab unless told otherwise', () => {
        useMediaModals().openWatched(mediaSummary());

        expect(useMediaModals().modals.watchedTab).toBe('watched');
    });

    it('closes every modal together', () => {
        const actions = useMediaModals();
        actions.openRate(mediaSummary());
        actions.openLists(mediaSummary());
        actions.openCreatorLists({ kind: 'company', provider: 'igdb', ref: '2' }, 'B');

        actions.close();

        expect(Object.values(actions.modals).filter((value) => value !== null && typeof value === 'object')).toEqual([]);
    });
});
