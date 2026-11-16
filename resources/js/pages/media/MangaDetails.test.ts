import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import MangaDetails from '@/pages/media/MangaDetails.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import type { MangaExtra } from '@/types/extras';
import type { DetailRow, MediaDetails } from '@/types/media';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

function manga(extra: MangaExtra = {}): MediaDetails<MangaExtra> {
    return {
        ...mediaSummary({ type: 'manga', ref: '23390', provider: 'mal', title: 'Attack on Titan' }),
        status: null,
        extra,
    } as MediaDetails<MangaExtra>;
}

function rows(extra: MangaExtra): DetailRow[] {
    const wrapper = shallowMount(MangaDetails, {
        props: { manga: manga(extra) },
        global: { stubs: { TitlePageLayout: false, AppLayout: { template: '<div><slot /></div>' } } },
    });

    return wrapper.findComponent(MediaTitleHeader).props('detailRows');
}

function valueOf(detailRows: DetailRow[], label: string): string | null | undefined {
    return detailRows.find((row) => row.label === label)?.value;
}

beforeEach(() => useMediaTypes());

describe('MangaDetails', () => {
    it('names the original title and the other names the manga goes by', () => {
        const detailRows = rows({ originalTitle: '進撃の巨人', alternativeTitles: ['Shingeki no Kyojin', 'AoT'] });

        expect(valueOf(detailRows, 'Original title')).toBe('進撃の巨人');
        expect(valueOf(detailRows, 'Alternative titles')).toBe('Shingeki no Kyojin, AoT');
    });

    it('leaves both rows empty for a manga MAL knows by one name', () => {
        const detailRows = rows({ alternativeTitles: [] });

        expect(valueOf(detailRows, 'Original title')).toBeNull();
        expect(valueOf(detailRows, 'Alternative titles')).toBeNull();
    });
});
