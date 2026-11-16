import { rankingTiles } from '@/lib/factTiles';
import { describe, expect, it } from 'vitest';

describe('rankingTiles', () => {
    it('names each ranking a title stands in, with its place', () => {
        const tiles = rankingTiles([
            { kind: 'score', position: 1 },
            { kind: 'popularity', position: 12 },
        ]);

        expect(tiles.map((tile) => [tile.key, tile.label, tile.value])).toEqual([
            ['score', 'By score', '#1'],
            ['popularity', 'By popularity', '#12'],
        ]);
    });

    it('has no tiles for a title without rankings', () => {
        expect(rankingTiles([])).toEqual([]);
    });
});
