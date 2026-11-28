import { describe, expect, it } from 'vitest';
import { isUnderWay, levelLook, statusLook, wordedText, writtenMoment } from './tasks';

describe('statusLook', () => {
    it("words every ending in the reader's language", () => {
        expect(statusLook('pending').label).toBe('Pending');
        expect(statusLook('started').spin).toBe(true);
        expect(statusLook('success').tone).toBe('text-success');
        expect(statusLook('failure').tone).toBe('text-danger');
    });
});

describe('isUnderWay', () => {
    it('is a run queued or at work, not one that ended or never was', () => {
        expect(isUnderWay('pending')).toBe(true);
        expect(isUnderWay('started')).toBe(true);
        expect(isUnderWay('success')).toBe(false);
        expect(isUnderWay(null)).toBe(false);
    });
});

describe('levelLook', () => {
    it('reads a check line in the colours of a run', () => {
        expect(levelLook('ok').tone).toBe(statusLook('success').tone);
        expect(levelLook('warning').tone).toBe('text-status-onhold');
        expect(levelLook('problem').tone).toBe(statusLook('failure').tone);
    });
});

describe('writtenMoment', () => {
    it('writes a moment of today by its hour, and one of another day with its date', () => {
        expect(writtenMoment('2026-10-10T10:30:00Z')).toBe('10:30 AM');
        expect(writtenMoment('2026-10-08T10:30:00Z')).toBe('Oct 8, 2026, 10:30 AM');
    });
});

describe('wordedText', () => {
    it('writes its moments into the sentence', () => {
        expect(wordedText({ text: 'Last answered at :time.', moments: { time: '2026-10-08T10:30:00Z' } })).toBe(
            'Last answered at Oct 8, 2026, 10:30 AM.',
        );
    });

    it('leaves a sentence without moments as it came', () => {
        expect(wordedText({ text: 'TMDB answers.', moments: {} })).toBe('TMDB answers.');
    });
});
