import ChannelLogo from '@/components/settings/channels/ChannelLogo.vue';
import manifest from '@/lib/channelLogos.json';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('ChannelLogo', () => {
    it("shows a service's logo file at its own proportions", () => {
        const image = mount(ChannelLogo, { props: { kind: 'tgram' } }).find('img');

        expect(image.attributes('src')).toBe(`/images/channels/${manifest.tgram.file}`);
        expect(image.attributes('width')).toBe(String(manifest.tgram.width));
        expect(image.attributes('height')).toBe(String(manifest.tgram.height));
        expect(image.attributes('alt')).toBe('');
        expect(image.attributes('loading')).toBe('lazy');
    });

    it('shows a plain icon for what has no brand, and a bell for a service without a logo yet', () => {
        const email = mount(ChannelLogo, { props: { kind: 'mail' } });
        const unknown = mount(ChannelLogo, { props: { kind: 'carrier-pigeon' } });

        expect(email.find('img').exists()).toBe(false);
        expect(email.find('svg').classes()).toContain('lucide-mail');
        expect(unknown.find('svg').classes()).toContain('lucide-bell');
    });
});
