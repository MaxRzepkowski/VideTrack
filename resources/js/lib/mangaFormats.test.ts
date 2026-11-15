import { mangaFormatLabel } from '@/lib/mangaFormats';
import { describe, expect, it } from 'vitest';

describe('mangaFormatLabel', () => {
    it("names a format in the reader's language", () => {
        expect(['manga', 'manhwa', 'manhua', 'one_shot', 'doujinshi', 'oel'].map(mangaFormatLabel)).toEqual([
            'Manga',
            'Manhwa',
            'Manhua',
            'One-shot',
            'Doujinshi',
            'OEL',
        ]);
    });

    it('shows a stored label that is not a code as it is, and nothing for nothing', () => {
        expect(mangaFormatLabel('Manhwa')).toBe('Manhwa');
        expect(mangaFormatLabel(null)).toBeNull();
        expect(mangaFormatLabel('')).toBeNull();
    });
});
