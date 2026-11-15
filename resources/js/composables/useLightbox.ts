import { mediaImage, type ImageSize } from '@/lib/images';
import { computed, ref } from 'vue';

/** Where a slide was opened from: the size the browser already holds of it, and the shape it reserves. */
export interface LightboxOrigin {
    thumbSize: ImageSize;
    width: number;
    height: number;
}

/**
 * The box a slide is shown in, whatever the file's own size (an `original`
 * runs to 3840 px): a landscape photo 1280 px wide and a poster in
 * its 2:3.
 */
const SHAPES = { landscape: { width: 1280, height: 720 }, poster: { width: 1000, height: 1500 } } as const;

export function lightboxOrigin(thumbSize: ImageSize, shape: keyof typeof SHAPES): LightboxOrigin {
    return { thumbSize, ...SHAPES[shape] };
}

/** The size a slide is shown at in full: what the image service keeps ready, so a first request is not made to wait. */
const FULL_SIZE: ImageSize = 'original';

function preloadImage(url: string): HTMLImageElement {
    const image = new Image();
    image.src = url;

    return image;
}

/**
 * A full-screen walk through a set of images. A step answers at once: the
 * slide's thumbnail is already in the browser and the full image lands on it,
 * and the images either side are fetched ahead while the lightbox is open.
 */
export function useLightbox() {
    const items = ref<string[]>([]);
    const index = ref(0);
    const origin = ref<LightboxOrigin>(lightboxOrigin('w780', 'landscape'));

    // Held so a fetch ahead is not dropped with its element.
    const ahead: HTMLImageElement[] = [];

    function holdAhead(images: HTMLImageElement[]): void {
        ahead.splice(0, ahead.length, ...images);
    }

    const current = computed<string | null>(() => items.value[index.value] ?? null);
    const fullSrc = computed(() => mediaImage(current.value, FULL_SIZE));
    const thumbSrc = computed(() => mediaImage(current.value, origin.value.thumbSize));

    function fetchNeighbours(): void {
        const count = items.value.length;

        if (count < 2) {
            holdAhead([]);

            return;
        }

        const neighbours = new Set([items.value[(index.value + 1) % count], items.value[(index.value - 1 + count) % count]]);

        holdAhead(
            [...neighbours]
                .map((path) => mediaImage(path, FULL_SIZE))
                .filter((url): url is string => url !== null)
                .map(preloadImage),
        );
    }

    function open(list: string[], path: string, from: LightboxOrigin): void {
        items.value = list;
        index.value = Math.max(0, list.indexOf(path));
        origin.value = from;
        fetchNeighbours();
    }

    function close(): void {
        items.value = [];
        holdAhead([]);
    }

    function step(delta: number): void {
        const count = items.value.length;

        if (count > 0) {
            index.value = (index.value + delta + count) % count;
            fetchNeighbours();
        }
    }

    return { items, current, origin, fullSrc, thumbSrc, open, close, step };
}
