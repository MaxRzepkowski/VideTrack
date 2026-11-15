import { useCreatorState } from '@/composables/useCreatorState';
import type { Creator } from '@/lib/creators';
import { setPageProps, visitPage } from '@/test/page';
import { describe, expect, it } from 'vitest';

const studio: Creator = { kind: 'company', provider: 'tmdb', ref: '174' };

describe('useCreatorState', () => {
    it("reads the page's creator states, a creator nobody touched as no bell and no lists", () => {
        const state = useCreatorState(studio);

        expect(state.value).toEqual({ followed: false, listIds: [] });

        setPageProps({ creatorStates: { 'company:tmdb:174': { followed: true, listIds: [3] } } });

        expect(state.value).toEqual({ followed: true, listIds: [3] });
        // A network with the same ref is another creator.
        expect(useCreatorState({ ...studio, kind: 'network' }).value.followed).toBe(false);
    });

    it('forgets what the old page held on leaving it', () => {
        setPageProps({ creatorStates: { 'company:tmdb:174': { followed: true, listIds: [] } } });
        visitPage();

        expect(useCreatorState(studio).value.followed).toBe(false);
    });
});
