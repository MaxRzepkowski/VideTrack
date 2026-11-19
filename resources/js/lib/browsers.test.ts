import { describeBrowser } from '@/lib/browsers';
import { describe, expect, it } from 'vitest';

describe('describeBrowser', () => {
    it.each([
        ['Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0', 'Firefox', 'Linux'],
        ['Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android'],
        [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0',
            'Edge',
            'Windows',
        ],
        [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
            'Safari',
            'iOS',
        ],
        ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Safari/605.1.15', 'Safari', 'macOS'],
        [
            'Mozilla/5.0 (Linux; Android 15; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36',
            'Samsung Internet',
            'Android',
        ],
    ])('names %s', (userAgent, browser, system) => {
        expect(describeBrowser(userAgent)).toEqual({ browser, system });
    });

    it('names nothing it does not know', () => {
        expect(describeBrowser('curl/8.0')).toEqual({ browser: null, system: null });
        expect(describeBrowser(null)).toEqual({ browser: null, system: null });
    });
});
