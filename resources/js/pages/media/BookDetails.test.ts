import CollectionBanner from '@/components/media/CollectionBanner.vue';
import LibraryFallbackNotice from '@/components/media/LibraryFallbackNotice.vue';
import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import OverviewSection from '@/components/media/OverviewSection.vue';
import RecommendationsBand from '@/components/media/RecommendationsBand.vue';
import BookDetails from '@/pages/media/BookDetails.vue';
import { credit, mediaSummary, useMediaTypes } from '@/test/factories';
import type { CreditData } from '@/types/generated';
import type { CollectionBannerData, MediaDetails, MediaSummary } from '@/types/media';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

function book(extra: Record<string, unknown> = {}, crew: CreditData[] = []): MediaDetails {
    const details = {
        ...mediaSummary({ type: 'book', ref: '2459845', provider: 'hardcover', title: 'The Hobbit' }),
        extra,
    } as MediaDetails;

    return { ...details, crew };
}

function render(props: {
    book: MediaDetails;
    fromLibrary?: boolean;
    removedAtSource?: boolean;
    collection?: CollectionBannerData | null;
    similar?: MediaSummary[];
}) {
    return shallowMount(BookDetails, {
        props,
        global: { stubs: { TitlePageLayout: false, AppLayout: { template: '<div><slot /></div>' }, LibraryFallbackNotice: false } },
    });
}

beforeEach(() => useMediaTypes());

describe('BookDetails', () => {
    it('chips the series position without a trailing .0, and the page count', () => {
        const wrapper = render({ book: book({ series: { name: 'Middle Earth', position: 1.0 }, pages: 320 }) });

        expect(wrapper.findComponent(MediaTitleHeader).props('chips')).toEqual(['Middle Earth #1', '320 pages']);
    });

    it('keeps a fractional series position', () => {
        const wrapper = render({ book: book({ series: { name: 'Middle Earth', position: 2.5 } }) });

        expect(wrapper.findComponent(MediaTitleHeader).props('chips')).toEqual(['Middle Earth #2.5']);
    });

    it('shows the banner of the series the book has a place in, and none without one', () => {
        const collection: CollectionBannerData = {
            provider: 'hardcover',
            ref: '127396',
            name: 'Middle Earth',
            backdropPath: null,
            partCount: 4,
            partType: 'book',
        };

        expect(render({ book: book() }).findComponent(CollectionBanner).exists()).toBe(false);
        expect(render({ book: book(), collection }).findComponent(CollectionBanner).props('collection')).toEqual(collection);
    });

    it('says when the page is served from the library', () => {
        expect(render({ book: book() }).findComponent(LibraryFallbackNotice).exists()).toBe(false);

        const wrapper = render({ book: book(), fromLibrary: true });

        expect(wrapper.text()).toContain('The data provider is unavailable');
    });

    it('shows the similar books once they arrive, under their own heading', () => {
        const dune = mediaSummary({ type: 'book', ref: '312460', provider: 'hardcover', title: 'Dune' });

        expect(render({ book: book() }).findComponent(RecommendationsBand).props('similar')).toEqual([]);

        const band = render({ book: book(), similar: [dune] }).findComponent(RecommendationsBand);

        expect(band.props('similar')).toEqual([dune]);
        expect(band.props('similarTitle')).toBe('Similar books');
    });

    it('chips the kind of a book that is not a plain book', () => {
        const wrapper = render({ book: book({ bookKind: 'graphic_novel', pages: 200 }) });

        expect(wrapper.findComponent(MediaTitleHeader).props('chips')).toEqual(['Graphic novel', '200 pages']);
    });

    it('credits the translator and illustrator in their parts after the authors', () => {
        const illustrator = [credit({ personRef: '205738', provider: 'hardcover', name: 'Alan Lee', department: 'art', role: 'illustrator' })];
        const details = book({ authorLinks: [{ name: 'J.R.R. Tolkien', kind: 'person', ref: '132049', provider: 'hardcover' }] }, illustrator);

        expect(render({ book: details }).findComponent(OverviewSection).props('people')).toEqual([
            { name: 'J.R.R. Tolkien', kind: 'person', ref: '132049', provider: 'hardcover', roles: ['Author'] },
            { name: 'Alan Lee', ref: '205738', provider: 'hardcover', kind: 'person', roles: ['Illustrator'] },
        ]);
    });

    it('lists the ISBN and whether it is fiction among the details', () => {
        const rows = render({ book: book({ isbn: '9780618260300', nonfiction: false }) })
            .findComponent(MediaTitleHeader)
            .props('detailRows');

        expect(rows).toContainEqual({ label: 'ISBN', value: '9780618260300' });
        expect(rows).toContainEqual({ label: 'Type', value: 'Fiction' });
    });

    it("names the reader's edition and the original title, and neither for a book in its own language", () => {
        const rows = (extra: Record<string, unknown>) =>
            render({ book: book(extra) })
                .findComponent(MediaTitleHeader)
                .props('detailRows');
        const polish = rows({ originalTitle: 'The Hobbit, or There and Back Again', localEdition: { publisher: 'Iskry', year: 2012 } });

        expect(polish).toContainEqual({ label: 'Original title', value: 'The Hobbit, or There and Back Again' });
        expect(polish).toContainEqual({ label: 'Edition in your language', value: 'Iskry, 2012' });
        expect(rows({ localEdition: { publisher: null, year: 2012 } })).toContainEqual({ label: 'Edition in your language', value: '2012' });
        expect(rows({})).toContainEqual({ label: 'Edition in your language', value: null });
    });

    it('says when the source no longer has the title', () => {
        const wrapper = render({ book: book(), fromLibrary: true, removedAtSource: true });

        expect(wrapper.findComponent(LibraryFallbackNotice).props('removed')).toBe(true);
    });
});
