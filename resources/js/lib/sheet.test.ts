import { SHEET_CLOSE_DISTANCE, SHEET_CLOSE_SPEED, sheetDragCloses } from '@/lib/sheet';
import { describe, expect, it } from 'vitest';

describe('sheetDragCloses', () => {
    it('closes a sheet pulled far enough, however slowly', () => {
        expect(sheetDragCloses(SHEET_CLOSE_DISTANCE, 2000)).toBe(true);
        expect(sheetDragCloses(SHEET_CLOSE_DISTANCE - 1, 2000)).toBe(false);
    });

    it('closes a short quick flick, and never a pull upwards or none', () => {
        expect(sheetDragCloses(30, 30 / SHEET_CLOSE_SPEED)).toBe(true);
        expect(sheetDragCloses(0, 0)).toBe(false);
        expect(sheetDragCloses(-40, 10)).toBe(false);
    });
});
