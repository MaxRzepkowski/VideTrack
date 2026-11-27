import { describe, expect, it } from 'vitest';
import { isUnderWay, statusLook } from './tasks';

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
