import ClampedText from '@/components/ClampedText.vue';
import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import RecommendationsBand from '@/components/media/RecommendationsBand.vue';
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

function render(details: MediaDetails<MangaExtra>) {
    return shallowMount(MangaDetails, {
        props: { manga: details },
        global: { stubs: { TitlePageLayout: false, AppLayout: { template: '<div><slot /></div>' } } },
    });
}

function rows(extra: MangaExtra): DetailRow[] {
    return render(manga(extra)).findComponent(MediaTitleHeader).props('detailRows');
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

    it('names the years it ran, its rank and its popularity as positions', () => {
        const details = { ...manga({ endDate: '2021-05-01', rank: 1, popularity: 12 }), releaseDate: '1989-08-25' };
        const detailRows: DetailRow[] = render(details).findComponent(MediaTitleHeader).props('detailRows');

        expect(valueOf(detailRows, 'Published')).toBe('1989–2021');
        expect(valueOf(detailRows, 'Rank')).toBe('#1');
        expect(valueOf(detailRows, 'Popularity')).toBe('#12');
        expect(valueOf(rows({}), 'Rank')).toBeNull();
    });

    it('credits the authors under what they did', () => {
        const details = { ...manga({ authors: ['Tsugumi Ohba'], authorRoles: { 'Tsugumi Ohba': ['story'] } }) };

        expect(render(details).findComponent(OverviewSection).props('people')).toMatchObject([{ name: 'Tsugumi Ohba', roles: ['Story'] }]);
    });

    it("shows MAL's background only when there is one, and the recommended manga under their own heading", () => {
        const vagabond = mediaSummary({ type: 'manga', ref: '656', provider: 'mal', title: 'Vagabond' });
        const withBackground = render({ ...manga({ background: 'Won the Tezuka Osamu Cultural Prize.' }), related: [vagabond] });

        expect(withBackground.findComponent(ClampedText).props('text')).toBe('Won the Tezuka Osamu Cultural Prize.');
        expect(render(manga()).findComponent(ClampedText).exists()).toBe(false);

        const band = withBackground.findComponent(RecommendationsBand);

        expect(band.props('related')).toEqual([vagabond]);
        expect(band.props('relatedTitle')).toBe('Recommended manga');
    });

    it('names no years for a manga still to come', () => {
        const upcoming = render({ ...manga(), status: 'not_yet_published', releaseDate: '2027-01-01' });

        expect(valueOf(upcoming.findComponent(MediaTitleHeader).props('detailRows'), 'Published')).toBeNull();
    });
});
