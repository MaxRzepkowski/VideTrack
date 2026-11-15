import { bookKindBadge, cardBadge, episodeBadge, gameTypeBadge, hasStateMarkers, leafCode } from '@/lib/badges';
import { mediaSummary, userState } from '@/test/factories';
import { describe, expect, it } from 'vitest';

const episode = (extra: Record<string, unknown>, number = 1) => mediaSummary({ type: 'episode', number, extra });

describe('leafCode', () => {
    it('codes an episode by season and number, and nothing else', () => {
        expect(leafCode(episode({ seasonNumber: 2 }, 9))).toBe('S02E09');
        expect(leafCode(mediaSummary({ type: 'movie' }))).toBeNull();
    });
});

describe('hasStateMarkers', () => {
    it('is on for a favourite or a title on a list', () => {
        expect(hasStateMarkers(userState())).toBe(false);
        expect(hasStateMarkers(userState({ favorite: true }))).toBe(true);
        expect(hasStateMarkers(userState({ listIds: [3] }))).toBe(true);
    });
});

describe('episodeBadge', () => {
    it('shows the milestone the server named', () => {
        expect(episodeBadge(episode({ episodeBadge: 'series_premiere' }))?.label).toBe('Series premiere');
        expect(episodeBadge(episode({ episodeBadge: 'season_premiere' }))?.label).toBe('Season premiere');
        expect(episodeBadge(episode({ episodeBadge: 'mid_season_finale' }))?.label).toBe('Mid-season finale');
        expect(episodeBadge(episode({ episodeBadge: 'season_finale' }))?.label).toBe('Season finale');
        expect(episodeBadge(episode({ episodeBadge: 'series_finale' }))?.label).toBe('Series finale');
    });

    it('shows nothing where the server named none', () => {
        expect(episodeBadge(episode({ episodeType: 'finale' }))).toBeNull();
        expect(episodeBadge(episode({ episodeBadge: null }))).toBeNull();
    });
});

describe('gameTypeBadge and cardBadge', () => {
    it('names a non-main game by its subtype', () => {
        expect(gameTypeBadge(mediaSummary({ type: 'game', extra: { gameType: 'dlc' } }))?.label).toBe('DLC');
        expect(gameTypeBadge(mediaSummary({ type: 'game' }))).toBeNull();
    });

    it('prefers the episode chip and falls back to the game subtype', () => {
        expect(cardBadge(episode({ episodeBadge: 'season_finale' }))?.label).toBe('Season finale');
        expect(cardBadge(mediaSummary({ type: 'game', extra: { gameType: 'remake' } }))?.label).toBe('Remake');
        expect(cardBadge(mediaSummary())).toBeNull();
    });
});

describe('bookKindBadge', () => {
    it('names a book that is not a plain book, and a card falls back to it', () => {
        const novella = mediaSummary({ type: 'book', extra: { bookKind: 'novella' } });

        expect(bookKindBadge(novella)?.label).toBe('Novella');
        expect(cardBadge(novella)?.label).toBe('Novella');
        expect(bookKindBadge(mediaSummary({ type: 'book' }))).toBeNull();
    });
});
