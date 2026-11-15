import type { VideoData } from '@/types/media';

/**
 * Only YouTube serves a thumbnail off a predictable URL. Vimeo's needs a call
 * to their oEmbed endpoint — a request to a third service for material that
 * turns up a few times a library — so its tiles carry the title alone.
 */
export function videoThumbnail(video: VideoData): string | null {
    return video.site === 'YouTube' ? `https://i.ytimg.com/vi/${video.key}/hqdefault.jpg` : null;
}

/** The player for a video, on the site that hosts it, starting at once. */
export function videoEmbedUrl(video: VideoData): string {
    return video.site === 'Vimeo'
        ? `https://player.vimeo.com/video/${video.key}?autoplay=1`
        : `https://www.youtube-nocookie.com/embed/${video.key}?autoplay=1`;
}
