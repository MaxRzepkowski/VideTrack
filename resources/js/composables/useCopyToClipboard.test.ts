import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';

const writeText = vi.fn();

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('navigator', { clipboard: { writeText } });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    writeText.mockReset();
});

describe('useCopyToClipboard', () => {
    it('says copied for two seconds, and a second copy restarts the moment', async () => {
        writeText.mockResolvedValue(undefined);
        const { copied, copy } = useCopyToClipboard();

        await copy('abc');
        expect(writeText).toHaveBeenCalledWith('abc');
        expect(copied.value).toBe(true);

        vi.advanceTimersByTime(1500);
        await copy('abc');
        vi.advanceTimersByTime(1500);
        expect(copied.value).toBe(true);

        vi.advanceTimersByTime(500);
        expect(copied.value).toBe(false);
    });

    it('tells the user when the clipboard is denied', async () => {
        writeText.mockRejectedValue(new Error('denied'));
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 1);
        const { copied, copy } = useCopyToClipboard();

        await copy('abc');
        expect(copied.value).toBe(false);
        expect(error).toHaveBeenCalledWith('Could not copy that.');

        await useCopyToClipboard('No luck').copy('x');
        expect(error).toHaveBeenLastCalledWith('No luck');
    });
});
