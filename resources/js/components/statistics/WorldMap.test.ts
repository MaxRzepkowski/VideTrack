import WorldMap from '@/components/statistics/WorldMap.vue';
import { ZOOM_STEP } from '@/composables/useZoom';
import { vTooltip } from '@/directives/tooltip';
import { MAP_GROUND } from '@/lib/origins';
import { setPageProps } from '@/test/page';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

beforeEach(() => {
    setPageProps({});
});

const mountMap = () =>
    mount(WorldMap, {
        props: { counts: { US: 12, PL: 2 }, describe: (code: string, titles: number) => `${code}: ${titles}` },
        global: { directives: { tooltip: vTooltip } },
    });

describe('WorldMap', () => {
    it('draws the world without Antarctica, each country shaded by its titles', async () => {
        const wrapper = mountMap();

        await vi.waitFor(() => expect(wrapper.findAll('path').length).toBeGreaterThan(100));

        const fills = wrapper.findAll('path').map((path) => path.attributes('fill'));

        expect(fills.filter((fill) => fill === 'var(--color-scale-4)')).toHaveLength(1);
        expect(fills.filter((fill) => fill === 'var(--color-scale-2)')).toHaveLength(1);
        expect(fills.filter((fill) => fill === MAP_GROUND.none).length).toBeGreaterThan(100);
    });

    it('zooms in by its button and back to the whole, the buttons lit only where they can act', async () => {
        const wrapper = mountMap();
        const [zoomIn, zoomOut, reset] = wrapper.findAll('button');

        expect(zoomOut!.attributes('disabled')).toBeDefined();
        expect(reset!.attributes('disabled')).toBeDefined();

        await zoomIn!.trigger('click');
        expect(wrapper.find('svg').attributes('style')).toContain(`scale(${ZOOM_STEP})`);
        expect(zoomOut!.attributes('disabled')).toBeUndefined();

        await reset!.trigger('click');
        expect(wrapper.find('svg').attributes('style')).toContain('scale(1)');
    });
});
