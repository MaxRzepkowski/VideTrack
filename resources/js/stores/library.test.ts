import { userState } from '@/test/factories';
import { setPageProps } from '@/test/page';
import { describe, expect, it, vi } from 'vitest';

describe('the library store at boot', () => {
    it('answers the untouched state before it follows the page, as the first render asks for it', async () => {
        // A fresh store, the way the app has it on its first render: mounted
        // before `followPage` runs.
        vi.resetModules();
        setPageProps({ untouchedState: userState({ rating: null }) });
        const { settledState } = await import('@/stores/library');

        expect(settledState('movie:m1')).toEqual(userState({ rating: null }));
    });
});

describe('a write that goes wrong', () => {
    it('says so when the answer is no JSON at all, instead of failing unheard', async () => {
        vi.resetModules();
        setPageProps({ untouchedState: userState() });
        const { toast } = await import('vue-sonner');
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 0);
        const { writeLibrary } = await import('@/stores/library');

        expect(await writeLibrary(() => Promise.resolve(new Response('<html>proxy</html>', { status: 200 })))).toBe(false);
        expect(error).toHaveBeenCalledWith('Something went wrong. Please try again.');
    });

    it('asks for a reload when the session has expired', async () => {
        vi.resetModules();
        setPageProps({ untouchedState: userState() });
        const { toast } = await import('vue-sonner');
        const error = vi.spyOn(toast, 'error').mockImplementation(() => 0);
        const { writeLibrary } = await import('@/stores/library');

        expect(await writeLibrary(() => Promise.resolve(new Response('', { status: 419 })))).toBe(false);
        expect(error).toHaveBeenCalledWith('Your session has expired. Reload the page and try again.');
    });
});
