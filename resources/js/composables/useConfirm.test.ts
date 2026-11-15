import { confirm, useConfirmHost } from '@/composables/useConfirm';
import { describe, expect, it } from 'vitest';

describe('confirm', () => {
    it('shows the request to the host and resolves with the answer', async () => {
        const host = useConfirmHost();
        const answer = confirm({ message: 'Delete the list?', confirmLabel: 'Delete' });

        expect(host.pending.value?.message).toBe('Delete the list?');
        host.answer(true);

        await expect(answer).resolves.toBe(true);
        expect(host.pending.value).toBeNull();
    });

    it('answers an open request no when another one comes', async () => {
        const host = useConfirmHost();
        const first = confirm({ message: 'First', confirmLabel: 'Go' });
        const second = confirm({ message: 'Second', confirmLabel: 'Go' });

        await expect(first).resolves.toBe(false);
        expect(host.pending.value?.message).toBe('Second');

        host.answer(false);
        await expect(second).resolves.toBe(false);
    });

    it('ignores an answer with nothing pending', () => {
        expect(() => useConfirmHost().answer(true)).not.toThrow();
    });
});
