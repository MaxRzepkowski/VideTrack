import RatingsSection from '@/components/statistics/RatingsSection.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { PeriodSpan, StatisticsRatings } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => {
    useMediaTypes();
    setPageProps({});
});

const year: PeriodSpan = { kind: 'year', year: 2025, from: '2025-01-01', to: '2025-12-31', days: 365 };

const ratings: StatisticsRatings = {
    points: [
        { type: 'movie', rating: 9, community: 6, titles: 1 },
        { type: 'movie', rating: 5, community: 7.5, titles: 2 },
        { type: 'show', rating: 8, community: 8, titles: 1 },
    ],
    ratedHigher: 1,
    ratedLower: 2,
    ratedSame: 1,
    best: [
        { media: mediaSummary({ title: 'Aspen', ref: '1' }), rating: 9 },
        { media: mediaSummary({ title: 'Cedar', ref: '2' }), rating: 9 },
    ],
    bestTied: 2,
    higher: [{ media: mediaSummary({ title: 'Cedar', ref: '2' }), rating: 9, community: 6 }],
    lower: [],
};

const mountSection = (props: { ratings: StatisticsRatings; type?: string | null; communityDelta?: number | null }) =>
    mount(RatingsSection, {
        props: { period: year, type: null, communityDelta: null, ...props },
        global: {
            stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
            directives: { tooltip: {} },
        },
    });

describe('RatingsSection', () => {
    it('counts the titles on each side of the diagonal and lists the gaps each way, saying when a side has none', () => {
        const text = mountSection({ ratings }).text();

        expect(text).toContain('2025, 4 titles rated by others. Above the diagonal you rate higher (1 title), below it lower (2 titles).');
        expect(text).not.toContain('On average');
        expect(text).toContain('You 9, others 6');
        expect(text).toContain('You rate no title lower than others.');
        expect(text).toContain('On the 1–10 track');
        expect(text).toContain('Aspen');
    });

    it("says the average gap in one type's view, and how many share the top rating when not all are shown", () => {
        const text = mountSection({ ratings: { ...ratings, bestTied: 12 }, type: 'movie', communityDelta: -0.76 }).text();

        expect(text).toContain('On average 0.76 below others.');
        expect(text).toContain('2025, 12 movies rated 9; the first by name shown.');
    });

    it("leaves the comparison out when no title has others' rating, and is gone with nothing rated", () => {
        const alone = mountSection({ ratings: { ...ratings, points: [], ratedHigher: 0, ratedLower: 0, ratedSame: 0, higher: [] } });

        expect(alone.text()).not.toContain('Above the diagonal');
        expect(alone.text()).toContain('Best rated');
        expect(
            mountSection({
                ratings: { ...ratings, points: [], ratedHigher: 0, ratedLower: 0, ratedSame: 0, best: [], bestTied: 0, higher: [] },
            }).html(),
        ).not.toContain('section');
    });
});
