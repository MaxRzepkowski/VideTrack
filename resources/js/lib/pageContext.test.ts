import { imageBase, pageLocale, viewerTimeFormat, viewerTimeZone } from '@/lib/pageContext';
import { setPageProps } from '@/test/page';
import { describe, expect, it } from 'vitest';

describe('pageContext', () => {
    it('reads the language and the image CDN the page shares', () => {
        setPageProps({ locale: 'pl', imageBase: 'https://cdn.test/t/p' });

        expect(pageLocale()).toBe('pl');
        expect(imageBase()).toBe('https://cdn.test/t/p');
    });

    it("reads the viewer's clock preferences, the browser's and the locale's when unset", () => {
        expect(viewerTimeZone()).toBeUndefined();
        expect(viewerTimeFormat()).toBe('auto');

        setPageProps({ auth: { user: { preferences: { timezone: 'Europe/Warsaw', time_format: '24' } } } });

        expect(viewerTimeZone()).toBe('Europe/Warsaw');
        expect(viewerTimeFormat()).toBe('24');
    });

    it('serves a guest page with the defaults', () => {
        setPageProps({ auth: { user: null } });

        expect(viewerTimeZone()).toBeUndefined();
        expect(viewerTimeFormat()).toBe('auto');
    });
});
