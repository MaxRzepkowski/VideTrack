import CertificationModal from '@/components/media/CertificationModal.vue';
import { vTooltip } from '@/directives/tooltip';
import type { CertificationRow } from '@/types/media';
import { DOMWrapper, flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

const row = (overrides: Partial<CertificationRow>): CertificationRow => ({
    key: 'k',
    name: 'Name',
    code: '18',
    meaning: null,
    descriptors: [],
    synopsis: null,
    image: null,
    ...overrides,
});

let wrapper: VueWrapper;

afterEach(() => wrapper.unmount());

const screen = () => new DOMWrapper(document.body);

async function open(rows: CertificationRow[]): Promise<void> {
    wrapper = mount(CertificationModal, {
        props: { label: 'PEGI 18', meaning: null, rows },
        global: { directives: { tooltip: vTooltip } },
        attachTo: document.body,
    });
    await wrapper.find('button').trigger('click');
    await flushPromises();
}

describe('CertificationModal', () => {
    it("lists each row with what it says: the content behind it and the board's own summary", async () => {
        await open([
            row({
                key: 'ESRB',
                name: 'ESRB',
                code: 'M',
                descriptors: [
                    { text: 'Blood and Gore', image: null },
                    { text: 'Strong Language', image: null },
                ],
                synopsis: 'An action game.',
            }),
            row({ key: 'PEGI', name: 'PEGI', code: '18' }),
        ]);

        expect(screen().text()).toContain('Certifications worldwide');
        expect(
            screen()
                .findAll('li .text-base')
                .map((name) => name.text()),
        ).toEqual(['ESRB', 'PEGI']);
        // Every reason the same tag, with or without an icon of its own.
        expect(
            screen()
                .findAll('li li')
                .map((tag) => tag.text()),
        ).toEqual(['Blood and Gore', 'Strong Language']);
        expect(screen().text()).toContain('An action game.');
    });

    it('names a single classification as one', async () => {
        await open([row({ name: 'Poland', code: '16', meaning: 'From 16' })]);

        expect(screen().text()).toContain('From 16');
        expect(screen().findAll('li .text-base')).toHaveLength(1);
    });

    it("draws a board's mark where one is kept, the code where not, and the mark on the chip", async () => {
        wrapper = mount(CertificationModal, {
            props: {
                label: 'PEGI 18',
                meaning: null,
                image: { src: '/images/age-ratings/pegi/18.png', width: 425, height: 519 },
                rows: [
                    row({
                        key: 'PEGI',
                        name: 'PEGI',
                        code: '18',
                        image: { src: '/images/age-ratings/pegi/18.png', width: 425, height: 519 },
                        descriptors: [{ text: 'Violence', image: { src: '/images/age-ratings/pegi/descriptors/violence.png', width: 1, height: 1 } }],
                    }),
                    row({ key: 'GRAC', name: 'GRAC', code: '18+' }),
                ],
            },
            global: { directives: { tooltip: vTooltip } },
            attachTo: document.body,
        });

        expect(wrapper.find('button img').attributes('alt')).toBe('PEGI 18');

        await wrapper.find('button').trigger('click');
        await flushPromises();

        // The mark in its column, the reason's icon decorating its words.
        expect(
            screen()
                .findAll('li img')
                .map((img) => img.attributes('alt')),
        ).toEqual(['PEGI 18', '']);
        expect(screen().find('li ul').attributes('aria-label')).toBe('Rating descriptors');
        expect(screen().find('li li').text()).toBe('Violence');
        expect(screen().findAll('li .text-base').at(-1)!.text()).toBe('GRAC');
        expect(screen().text()).toContain('18+');
    });
});
