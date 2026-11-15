import MediaActionBar from '@/components/media/MediaActionBar.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { UserMediaState } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const game = mediaSummary({ type: 'game', ref: 'g1' });

beforeEach(() => useMediaTypes());

/** The wide bar's status cell, as the bar draws it for a game in this state. */
function statusCell(state: Partial<UserMediaState>) {
    setPageProps({ userLists: [], userStates: { 'game:g1': userState(state) } });

    return mount(MediaActionBar, { props: { media: game }, global: { directives: { tooltip: vTooltip } } }).findAll(
        'button[aria-haspopup="dialog"]',
    )[1];
}

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
});
