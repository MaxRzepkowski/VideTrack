import { usePlayHistory } from '@/composables/usePlayHistory';
import { mediaSummary } from '@/test/factories';
import { nth } from '@/test/lists';
import type { MediaSummary } from '@/types/media';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

const answers: { url: string; resolve: (response: Response) => void; reject: (error: unknown) => void }[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string) => new Promise<Response>((resolve, reject) => answers.push({ url, resolve, reject })),
}));

afterEach(() => {
    answers.length = 0;
    vi.restoreAllMocks();
});

const movie = mediaSummary({ type: 'movie', ref: 'm1' });
const play = { id: 7, watchedAt: '2026-10-01T20:00:00Z', outcome: 'completed' as const, amount: null, cycle: 1 };

function answer(index: number, status: number, data: unknown = []): Promise<void> {
    nth(answers, index).resolve(new Response(JSON.stringify({ data }), { status }));

    return new Promise((resolve) => setTimeout(resolve));
}

describe('reading the history', () => {
    it("lists the title's plays and cycles", async () => {
        const { plays, cycles, loadPlays, loadCycles } = usePlayHistory(() => movie);

        const loading = [loadPlays(), loadCycles()];

        expect(answers.map((call) => call.url)).toEqual(['/ui.media.plays.index/movie/m1', '/ui.media.cycles.index/movie/m1']);

        await answer(0, 200, [play]);
        await answer(1, 200, [{ number: 1 }]);
        await Promise.all(loading);

        expect(plays.value).toEqual([play]);
        expect(cycles.value).toEqual([{ number: 1 }]);
    });

    it('says a failed read failed instead of showing an empty history', async () => {
        const error = vi.spyOn(toast, 'error').mockReturnValue(1);
        const { plays, loadPlays } = usePlayHistory(() => movie);

        const loading = loadPlays();

        await answer(0, 500);
        await loading;

        const offline = loadPlays();

        nth(answers, 1).reject(new TypeError('Failed to fetch'));
        await offline;

        expect(error).toHaveBeenCalledTimes(2);
        expect(plays.value).toEqual([]);
    });

    it('drops an answer that lands after the modal moved on to another title', async () => {
        const current = ref<MediaSummary | null>(movie);
        const { plays, loadPlays } = usePlayHistory(() => current.value);

        const loading = loadPlays();

        current.value = mediaSummary({ type: 'movie', ref: 'm2' });
        await answer(0, 200, [play]);
        await loading;

        expect(plays.value).toEqual([]);
    });

    it('reads nothing with no title open', async () => {
        const { loadPlays } = usePlayHistory(() => null);

        await loadPlays();

        expect(answers).toHaveLength(0);
    });
});
