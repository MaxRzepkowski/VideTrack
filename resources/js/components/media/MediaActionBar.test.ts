import MediaActionBar from '@/components/media/MediaActionBar.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { UserMediaState } from '@/types/generated';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

const game = mediaSummary({ type: 'game', ref: 'g1' });

beforeEach(() => useMediaTypes());

/** The wide bar's status cell, as the bar draws it for a game in this state. */
function statusCell(state: Partial<UserMediaState>) {
    setPageProps({ userLists: [], userStates: { 'game:g1': userState(state) } });

    return mount(MediaActionBar, { props: { media: game }, global: { directives: { tooltip: vTooltip } } }).findAll(
        'button[aria-haspopup="dialog"]',
    )[1];
}

/** The narrow card's "…" menu, opened from the keyboard, as its rows read for a game in this state. */
async function menuRows(state: Partial<UserMediaState>): Promise<string[]> {
    setPageProps({ userLists: [], userStates: { 'game:g1': userState(state) } });
    const bar = mount(MediaActionBar, { props: { media: game }, attachTo: document.body, global: { directives: { tooltip: vTooltip } } });
    await bar.find('button[aria-haspopup="menu"]').trigger('keydown', { key: 'Enter' });
    await flushPromises();

    // Each row's name and, under it, where the title stands.
    return [...document.querySelectorAll('[role="menuitem"]')].map((row) =>
        [...row.querySelectorAll(':scope > span:nth-child(2) > span')].map((line) => line.textContent?.trim()).join(' / '),
    );
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the narrow card menu', () => {
    it('says where an untouched title stands with each mark', async () => {
        expect(await menuRows({})).toEqual(['Rate / Not rated', 'Add to list / Not on any list', 'Add to favorites']);
    });

    it('says the rating, the lists and the heart a title holds', async () => {
        expect(await menuRows({ rating: 7, listIds: [3, 5], favorite: true })).toEqual([
            'Rate / Rated 7/10',
            'Manage lists / On 2 lists',
            'Remove from favorites / In favorites',
        ]);
    });
});

describe('MediaActionBar', () => {
    it.each([
        ['finished', { watched: true }, '--color-action-watched'],
        ['finished and started again', { watched: true, status: 'in_progress' }, '--color-status-progress'],
        ['finished and then dropped', { watched: true, status: 'dropped' }, '--color-status-dropped'],
        ['finished and put on hold', { watched: true, status: 'on_hold' }, '--color-status-onhold'],
    ] as const)('colours a game %s as the status card does', (_, state, colour) => {
        expect(statusCell(state)?.attributes('style')).toContain(colour);
    });

    it('leaves an untouched game uncoloured', () => {
        expect(statusCell({})?.attributes('style')).toBeUndefined();
    });

    it('says when a finished game was finished, above what a click does', () => {
        expect(statusCell({ watched: true, watchedAt: '2026-03-12T20:00:00+00:00', plays: 2 })?.attributes('aria-label')).toBe(
            'Completed March 12, 2026 – 2×\nChange status',
        );
        // Started again: the status speaks, not the last finish.
        expect(statusCell({ watched: true, watchedAt: '2026-03-12T20:00:00+00:00', plays: 2, status: 'in_progress' })?.attributes('aria-label')).toBe(
            'Change status',
        );
    });
});
