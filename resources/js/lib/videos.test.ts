import { videoEmbedUrl, videoThumbnail } from '@/lib/videos';
import type { VideoData } from '@/types/media';
import { describe, expect, it } from 'vitest';

const video = (site: VideoData['site']): VideoData => ({ key: 'abc', site, type: 'Trailer', name: 'Trailer', official: true, publishedAt: null });

describe('videos', () => {
    it('plays a video on the site that hosts it', () => {
        expect(videoEmbedUrl(video('YouTube'))).toBe('https://www.youtube-nocookie.com/embed/abc?autoplay=1');
        expect(videoEmbedUrl(video('Vimeo'))).toBe('https://player.vimeo.com/video/abc?autoplay=1');
    });

    it('has a thumbnail only where the host serves one off a known address', () => {
        expect(videoThumbnail(video('YouTube'))).toBe('https://i.ytimg.com/vi/abc/hqdefault.jpg');
        expect(videoThumbnail(video('Vimeo'))).toBeNull();
    });
});
