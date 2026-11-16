import ClampedText from '@/components/ClampedText.vue';
import FactTilesSection from '@/components/media/FactTilesSection.vue';
import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import RecommendationsBand from '@/components/media/RecommendationsBand.vue';
import RelatedTitles from '@/components/media/RelatedTitles.vue';
import MangaDetails from '@/pages/media/MangaDetails.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import type { MangaExtra } from '@/types/extras';
import type { RankingPosition } from '@/types/generated';
import type { DetailRow, MediaDetails, TitleTie } from '@/types/media';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

function manga(extra: MangaExtra = {}): MediaDetails<MangaExtra> {
    return {
        ...mediaSummary({ type: 'manga', ref: '23390', provider: 'mal', title: 'Attack on Titan' }),
        status: null,
        rankings: [] as RankingPosition[],
        extra,
    } as MediaDetails<MangaExtra>;
}

function render(details: MediaDetails<MangaExtra>, relatedTitles: TitleTie[] = []) {
    return shallowMount(MangaDetails, {
        props: { manga: details, relatedTitles },
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

    it('names the years it ran among the details, and its rankings as tiles', () => {
        const details = {
            ...manga({ endDate: '2021-05-01' }),
            releaseDate: '1989-08-25',
            rankings: [
                { kind: 'score' as const, position: 1 },
                { kind: 'popularity' as const, position: 12 },
            ],
        };
        const wrapper = render(details);
        const detailRows: DetailRow[] = wrapper.findComponent(MediaTitleHeader).props('detailRows');

        expect(valueOf(detailRows, 'Published')).toBe('1989–2021');
        expect(wrapper.findComponent(FactTilesSection).props('title')).toBe('Rankings');
        expect(
            wrapper
                .findComponent(FactTilesSection)
                .props('tiles')
                .map((tile: { value: string }) => tile.value),
        ).toEqual(['#1', '#12']);
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

    it('shows the manga MAL ties to it, without naming a source', () => {
        const gaiden: TitleTie = { relation: 'spin_off', label: 'Spin-off', media: mediaSummary({ type: 'manga', ref: '44001', provider: 'mal' }) };
        const section = render(manga(), [gaiden]).findComponent(RelatedTitles);

        expect(section.props('titles')).toEqual([gaiden]);
        expect(section.props('source')).toBeNull();
    });
});
