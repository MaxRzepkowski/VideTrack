import DeliveryLog from '@/components/settings/integrations/DeliveryLog.vue';
import type { DeliveryNote } from '@/types/generated';
import type { DeliveryRow } from '@/types/integrations';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function delivery(overrides: Partial<DeliveryRow>): DeliveryRow {
    return { id: 1, status: 'ignored', kind: null, label: null, message: null, at: null, payload: null, ...overrides };
}

const mountLog = (deliveries: DeliveryRow[]) => mount(DeliveryLog, { props: { deliveries } });

describe('DeliveryLog', () => {
    it('names a saved delivery by its title and says it reached the library', () => {
        const text = mountLog([delivery({ status: 'accepted', kind: 'complete', label: 'The Matrix (1999)' })]).text();

        expect(text).toContain('The Matrix (1999)');
        expect(text).toContain('Watched to the end');
        expect(text).toContain('Saved to your library');
    });

    it('says in words why a delivery ended as it did', () => {
        const text = mountLog([delivery({ kind: 'complete', label: 'The Matrix (1999)', message: 'already_played' })]).text();

        expect(text).toContain('Already counted as watched.');
    });

    it('heads a delivery without a title by its kind, and one without either as unrecognized', () => {
        const log = mountLog([delivery({ id: 1, kind: 'pause' }), delivery({ id: 2, message: 'not_an_event' })]);
        const rows = log.findAll('li').map((row) => row.text());

        expect(rows[0]).toContain('Playback paused');
        expect(rows[1]).toContain('Unrecognized delivery');
        expect(rows[1]).toContain('Not a playback event.');
    });

    it('unfolds what arrived only where it was kept', async () => {
        const log = mountLog([
            delivery({ id: 1, message: 'not_an_event', payload: '{"something": "else"}' }),
            delivery({ id: 2, message: 'not_an_event' }),
        ]);

        expect(log.findAll('button')).toHaveLength(1);

        await log.find('button').trigger('click');

        expect(log.find('pre').text()).toBe('{"something": "else"}');
        expect(log.find('button').text()).toBe('Hide what arrived');
    });

    it('has words for every note and every kind of event', () => {
        const notes: DeliveryNote[] = [
            'already_played',
            'below_threshold',
            'duplicate_delivery',
            'invalid_payload',
            'kind_not_actionable',
            'no_external_id_match',
            'no_progress_unit',
            'not_an_event',
            'parse_failed',
            'payload_too_large',
            'processing_failed',
            'rating_not_imported',
            'rating_out_of_range',
            'test_parse_failed',
            'test_passed',
            'test_provider_unreachable',
            'user_mismatch',
        ];
        const kinds = ['complete', 'stop', 'start', 'pause', 'progress', 'rate', 'added'];
        const rows = mountLog([
            ...notes.map((message, index) => delivery({ id: index + 1, message })),
            ...kinds.map((kind, index) => delivery({ id: 100 + index, kind })),
        ]).findAll('li');

        expect(rows).toHaveLength(notes.length + kinds.length);
        rows.forEach((row) => expect(row.text()).not.toMatch(/integrations\./));
    });
});
