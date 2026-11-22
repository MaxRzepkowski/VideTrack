import MediaStatusPanel from '@/components/media/MediaStatusPanel.vue';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { UserMediaState } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => useMediaTypes());

/** The first row's words, for a title of this type in this state. */
function statusRow(type: string, state: Partial<UserMediaState>): string {
    setPageProps({ userLists: [{ id: 5, systemKey: 'watchlist', itemCount: 0 }], userStates: { [`${type}:1`]: userState(state) } });

    return mount(MediaStatusPanel, { props: { media: mediaSummary({ type, ref: '1' }) } })
        .find('button')
        .text();
}

describe('MediaStatusPanel', () => {
    it('names the state in the order every surface reads it', () => {
        expect(statusRow('game', { watched: true, status: 'in_progress' })).toContain('Playing');
        expect(statusRow('season', { status: 'dropped', statusInherited: true })).toContain('Dropped with the show');
        expect(statusRow('show', { watchedEpisodes: 3, airedEpisodes: 10 })).toContain('In progress');
        expect(statusRow('movie', { watched: true })).toContain('Watched');
    });

    it('reads as its action while nothing is set, and as the backlog once it is on it', () => {
        expect(statusRow('movie', {})).toContain('Mark as watched');
        expect(statusRow('movie', { listIds: [5] })).toContain('Backlog');
    });
});
