import ServiceLogo from '@/components/ServiceLogo.vue';
import manifest from '@/lib/serviceLogos.json';
import { Bell, FileUp } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('ServiceLogo', () => {
    it("shows a service's logo file at its own proportions", () => {
        const image = mount(ServiceLogo, { props: { code: 'tgram', fallback: Bell } }).find('img');

        expect(image.attributes('src')).toBe(`/images/logos/${manifest.tgram.file}`);
        expect(image.attributes('width')).toBe(String(manifest.tgram.width));
        expect(image.attributes('height')).toBe(String(manifest.tgram.height));
        expect(image.attributes('alt')).toBe('');
        expect(image.attributes('loading')).toBe('lazy');
    });

    it("shows a plain icon for what has no brand, and the caller's stand-in for a service without a logo yet", () => {
        const email = mount(ServiceLogo, { props: { code: 'mail', fallback: Bell } });
        const unknown = mount(ServiceLogo, { props: { code: 'carrier-pigeon', fallback: Bell } });

        expect(email.find('img').exists()).toBe(false);
        expect(email.find('svg').classes()).toContain('lucide-mail');
        expect(unknown.find('svg').classes()).toContain('lucide-bell');
    });

    it("shows the caller's stand-in for a service without a logo", () => {
        const unknown = mount(ServiceLogo, { props: { code: 'carrier-pigeon', fallback: FileUp } });

        expect(unknown.find('svg').classes()).toContain('lucide-file-up');
    });
});
