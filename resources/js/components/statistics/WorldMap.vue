<script setup lang="ts">
import ZoomControls from '@/components/ZoomControls.vue';
import { useZoom } from '@/composables/useZoom';
import { countryShade } from '@/lib/origins';
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref, shallowRef } from 'vue';

/**
 * The world with each country shaded by how many of the period's titles came
 * from it. The outlines and the projection load with the first map drawn, not
 * with the page: most visits never scroll this far.
 */
const props = defineProps<{
    /** ISO 3166-1 alpha-2 code => titles. */
    counts: Record<string, number>;
    /** The tooltip of a country with titles. */
    describe: (code: string, titles: number) => string;
}>();

const WIDTH = 1136;
const HEIGHT = 503;
/** ISO 3166-1 numeric code of Antarctica: no title comes from it, and it would take a fifth of the height. */
const ANTARCTICA = '010';

type Country = { key: string; path: string; code: string | null };

/** Close enough to tell the small countries of Europe and the Caribbean apart. */
const MAX_ZOOM = 8;

const countries = shallowRef<Country[]>([]);
const viewport = ref<HTMLElement | null>(null);
const zoom = useZoom(viewport, ref(MAX_ZOOM));

onMounted(async () => {
    const [{ geoNaturalEarth1, geoPath }, { feature }, atlas, codes] = await Promise.all([
        import('d3-geo'),
        import('topojson-client'),
        import('world-atlas/countries-110m.json'),
        import('@/lib/countryCodes.json'),
    ]);
    const topology = atlas.default;
    const alpha2: Record<string, string> = codes.default;
    const world = feature(topology, topology.objects.countries);
    const land = { ...world, features: world.features.filter((country) => country.id !== ANTARCTICA) };
    const path = geoPath(geoNaturalEarth1().fitSize([WIDTH, HEIGHT], land));

    countries.value = land.features.map((country, index) => ({
        key: String(country.id ?? `unnamed-${index}`),
        path: path(country) ?? '',
        code: country.id === undefined ? null : (alpha2[String(country.id)] ?? null),
    }));
});

const titlesOf = (country: Country): number => (country.code === null ? 0 : (props.counts[country.code] ?? 0));
</script>

<template>
    <div
        ref="viewport"
        class="bg-surface-raised relative overflow-hidden rounded"
        :class="zoom.zoomed.value ? 'cursor-grab touch-none select-none' : 'touch-pan-y'"
        @wheel="zoom.onWheel"
        @pointerdown="zoom.onPointerdown"
        @pointermove="zoom.onPointermove"
        @click.capture="zoom.onClickCapture"
    >
        <svg
            :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
            class="block h-auto w-full origin-top-left"
            :style="{ transform: `translate(${zoom.x.value}px, ${zoom.y.value}px) scale(${zoom.scale.value})` }"
            role="img"
            :aria-label="trans('statistics.origins_map_label')"
        >
            <path
                v-for="country in countries"
                :key="country.key"
                v-tooltip="country.code !== null && titlesOf(country) > 0 ? describe(country.code, titlesOf(country)) : null"
                :d="country.path"
                :fill="countryShade(titlesOf(country))"
                stroke="var(--color-surface-raised)"
                stroke-width="0.6"
                vector-effect="non-scaling-stroke"
            />
        </svg>
        <ZoomControls
            class="absolute top-3 right-3"
            :scale="zoom.scale.value"
            :max="MAX_ZOOM"
            @in="zoom.zoomIn"
            @out="zoom.zoomOut"
            @reset="zoom.reset"
        />
    </div>
</template>
