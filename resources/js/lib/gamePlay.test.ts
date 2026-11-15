import { languageRows, multiplayerLabel, providerRatingLabel } from '@/lib/gamePlay';
import { setPageProps } from '@/test/page';
import type { LanguageSupport } from '@/types/generated';
import { describe, expect, it } from 'vitest';

const speaks = (locale: string, kinds: Partial<Omit<LanguageSupport, 'locale'>>): LanguageSupport => ({
    locale,
    audio: false,
    subtitles: false,
    interface: false,
    ...kinds,
});

describe('languageRows', () => {
    const languages = [
        speaks('en-US', { audio: true, subtitles: true }),
        speaks('es-ES', { subtitles: true }),
        speaks('es-MX', { subtitles: true }),
        speaks('pl-PL', { subtitles: true, interface: true }),
        speaks('zh-TW', { subtitles: true }),
    ];

    it("names each language in the reader's language, theirs first, a variant only where the language comes twice", () => {
        expect(languageRows(languages).map((row) => row.name)).toEqual([
            'English',
            'European Spanish',
            'Mexican Spanish',
            'Polish',
            'Traditional Chinese',
        ]);

        setPageProps({ locale: 'pl' });
        const rows = languageRows(languages);

        expect(rows[0]).toEqual({ locale: 'pl-PL', name: 'polski', audio: false, subtitles: true, interface: true });
        expect(rows.map((row) => row.name).slice(1, 3)).toEqual(['angielski', 'chiński tradycyjny']);
    });
});

describe('multiplayerLabel', () => {
    it('says how many may play where the provider knows', () => {
        expect(multiplayerLabel({ mode: 'online_coop', players: 4 })).toBe('Online co-op (up to 4 players)');
        expect(multiplayerLabel({ mode: 'split_screen', players: null })).toBe('Split screen');
    });
});

describe('providerRatingLabel', () => {
    it('gives the score and how many gave it, or nothing', () => {
        expect(providerRatingLabel({ score: 9.1, count: 1203 }, 'media.count_player_ratings')).toBe('91% – 1,203 ratings');
        expect(providerRatingLabel({ score: 8.8, count: 1 }, 'media.count_critic_reviews')).toBe('88% – 1 review');
        expect(providerRatingLabel(null, 'media.count_critic_reviews')).toBeNull();
    });
});
