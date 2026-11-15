import { ageRatingImage, descriptorImage } from '@/lib/ageRatings';
import { existsSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

describe('ageRatingImage', () => {
    it("finds a board's mark by the rating as the board writes it", () => {
        expect(ageRatingImage('PEGI', '18')).toEqual({ src: '/images/age-ratings/pegi/18.png', width: 105, height: 128 });
        expect(ageRatingImage('ESRB', 'E10+')?.src).toBe('/images/age-ratings/esrb/e10.png');
        expect(ageRatingImage('ACB', 'MA 15+')?.src).toBe('/images/age-ratings/acb/ma_15.png');
        expect(ageRatingImage('ClassInd', 'L')?.src).toBe('/images/age-ratings/class_ind/l.png');
    });

    it('has none for a rating it keeps no mark of', () => {
        expect(ageRatingImage('Nobody', '18')).toBeNull();
    });

    it('points at files the app ships', () => {
        for (const [board, rating] of [
            ['PEGI', '3'],
            ['USK', '0'],
            ['CERO', 'Z'],
            ['GRAC', '19+'],
            ['GRAC', '18+'],
        ] as const) {
            expect(existsSync(`public${ageRatingImage(board, rating)?.src}`)).toBe(true);
        }
    });

    it("finds a board's mark for the content it names, none where the board writes words", () => {
        expect(descriptorImage('PEGI', 'Bad Language')?.src).toBe('/images/age-ratings/pegi/descriptors/bad-language.png');
        expect(descriptorImage('GRAC', 'Crime, Anti-Social')?.src).toBe('/images/age-ratings/grac/descriptors/crime-anti-social.png');
        expect(existsSync(`public${descriptorImage('CERO', 'Drinking/Smoking')?.src}`)).toBe(true);
        expect(descriptorImage('ESRB', 'Blood and Gore')).toBeNull();
    });
});
