import MediaTitleHeader from '@/components/media/MediaTitleHeader.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import type { CertificationRow, Chip, MediaDetails } from '@/types/media';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const CertificationModal = { props: ['label', 'meaning', 'rows'], template: '<button class="certification">{{ label }}</button>' };

function details(overrides: Partial<MediaDetails> = {}): MediaDetails {
    return {
        ...mediaSummary({ title: 'Dune', communityRating: 8.4 }),
        tagline: null,
        overview: null,
        runtime: null,
        genres: [],
        status: null,
        originalLanguage: null,
        certification: '16',
        certifications: [
            { region: 'US', code: 'PG-13', meaning: 'Parents strongly cautioned' },
            { region: 'DE', code: '12', meaning: null },
        ],
        releases: [],
        platformReleases: [],
        keywords: [],
        externalLinks: {},
        children: [],
        cast: [],
        crew: [],
        videos: [],
        backdrops: [],
        posters: [],
        logos: [],
        related: [],
        similar: [],
        ...overrides,
        extra: { certificationRegion: 'US', certificationDescriptors: ['Violence'], ...(overrides.extra ?? {}) },
    } as MediaDetails;
}

function header(chips: Chip[], props: { details?: MediaDetails; ratingHint?: { label: string; value: string }[] | null } = {}) {
    return mount(MediaTitleHeader, {
        props: { details: props.details ?? details(), chips, detailRows: [], ratingHint: props.ratingHint ?? null },
        global: { stubs: { CertificationModal, DetailsModal: true, Link: true }, directives: { tooltip: vTooltip } },
    });
}

beforeEach(() => useMediaTypes());

describe('MediaTitleHeader', () => {
    it("opens a film's classifications by country, the viewer's own with its descriptors, alphabetically", () => {
        const rows = header([{ label: 'PG-13', certification: true }])
            .findComponent(CertificationModal)
            .props('rows') as CertificationRow[];

        expect(rows.map((row) => [row.name, row.code, row.descriptors])).toEqual([
            ['Germany', '12', []],
            ['United States', 'PG-13', [{ text: 'Violence', image: null }]],
        ]);
    });

    it("opens a game's boards as the chip brings them", () => {
        const boards: CertificationRow[] = [
            { key: 'PEGI', name: 'PEGI', code: '18', meaning: null, descriptors: [{ text: 'Violence', image: null }], synopsis: null, image: null },
        ];

        expect(
            header([{ label: 'PEGI 18', certification: true, rows: boards }])
                .findComponent(CertificationModal)
                .props('rows'),
        ).toEqual(boards);
    });

    it('lets the score be opened only when it has parts to show', () => {
        const plain = header([]);
        const split = header([], { ratingHint: [{ label: 'Critics', value: '88% – 12 reviews' }] });

        expect(plain.find('[tabindex="0"]').exists()).toBe(false);
        expect(split.find('[tabindex="0"]').text()).toBe('84%');
    });

    it("names a game's porting and supporting studios in the details", () => {
        const studio = (name: string) => ({ name, kind: 'company' as const, ref: '1', provider: 'igdb' });
        const wrapper = mount(MediaTitleHeader, {
            props: {
                details: details({ extra: { portingLinks: [studio('Port Co')], supportingLinks: [studio('Help Co')], developerLinks: [] } }),
                chips: [],
                detailRows: [],
            },
            global: { stubs: { CertificationModal, DetailsModal: true, Link: true }, directives: { tooltip: vTooltip } },
        });
        const modal = wrapper.findComponent({ name: 'DetailsModal' });

        expect((modal.props('creatorRows') as { label: string }[]).map((row) => row.label)).toEqual(['Porting', 'Support']);
    });
});
