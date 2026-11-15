import CastSection from '@/components/media/CastSection.vue';
import type { CastPerson } from '@/lib/credits';
import { credit } from '@/test/factories';
import type { CollectionCredit, CreditData } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { transChoice } from 'laravel-vue-i18n';
import { describe, expect, it } from 'vitest';

const showCredit = (overrides: Partial<CreditData> = {}): CreditData =>
    credit({ episodeCount: 62, roles: [{ role: 'Walter White', episodeCount: 62 }], ...overrides });

const sagaCredit = (overrides: Partial<CollectionCredit> = {}): CollectionCredit => ({
    personRef: '6384',
    provider: 'tmdb',
    name: 'Keanu Reeves',
    profilePath: null,
    department: 'acting',
    roles: [{ role: 'Neo' }, { role: 'Thomas Anderson' }],
    titleCount: 4,
    ...overrides,
});

// The tile's lines under the portrait: the name, the role, the caption.
function tileLines(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper.findAll('.cast-scroll a > div:not(:first-child)').map((line) => line.text());
}

describe('the cast strip', () => {
    it("says in how many episodes a show's actor appears", () => {
        const wrapper = mount(CastSection, { props: { cast: [showCredit()], crew: [] } });

        expect(tileLines(wrapper)).toEqual(['Bryan Cranston', 'Walter White', '62 episodes']);
    });

    it("says nothing under a film's actor, who has no episode count", () => {
        const wrapper = mount(CastSection, { props: { cast: [showCredit({ episodeCount: null, roles: [] })], crew: [] } });

        expect(tileLines(wrapper)).toEqual(['Bryan Cranston', 'Walter White']);
    });

    it("takes the page's own caption: a collection says in how many films, with every character", () => {
        const wrapper = mount(CastSection, {
            props: {
                cast: [sagaCredit()],
                crew: [],
                // mount() does not infer the component's credit type from its props.
                caption: ((credit: CollectionCredit) => transChoice('common.count_movie', credit.titleCount)) as (credit: CastPerson) => string,
            },
        });

        expect(tileLines(wrapper)).toEqual(['Keanu Reeves', 'Neo / Thomas Anderson', '4 movies']);
    });

    it('is not there without a cast', () => {
        expect(
            mount(CastSection, { props: { cast: [], crew: [] } })
                .find('section')
                .exists(),
        ).toBe(false);
    });
});
