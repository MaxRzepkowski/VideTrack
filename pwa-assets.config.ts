import { defineConfig } from '@vite-pwa/assets-generator/config';

// The source is the "vi" of public/logo.svg on a tile of --color-surface; a change to the logo is drawn there too.
// The source draws its own rounded tile, so the "any" icons and the favicon keep it edge to edge. Maskable and
// Apple icons sit on the tile colour: the tile blends into the square, and maskable shrinks the mark into the
// safe circle that launchers crop to.
const tile = { background: '#141418' };

export default defineConfig({
    images: ['public/favicon.svg'],
    preset: {
        transparent: { sizes: [192, 512], padding: 0, favicons: [[48, 'favicon.ico']] },
        maskable: { sizes: [512], padding: 0.15, resizeOptions: tile },
        apple: { sizes: [180], padding: 0, resizeOptions: tile },
    },
});
