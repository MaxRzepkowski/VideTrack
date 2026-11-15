import { leafCode, showTitleOf } from '@/lib/badges';
import { creatorOf } from '@/lib/creators';
import type { ListItemEntry, MediaSummary } from '@/types/media';

/**
 * A position's name as a reader says it: an episode or a season under its
 * show ("Show – S02E05 – Title"), anything else by its own title.
 */
function nameOf(media: MediaSummary): string {
    if (creatorOf(media)) {
        return media.title;
    }

    return [showTitleOf(media), leafCode(media), media.title].filter((part): part is string => !!part).join(' – ');
}

/**
 * A list as plain text, a position per line: "Title (year)", an episode or a
 * season under its show, a person or a company by name alone.
 */
export function listText(positions: MediaSummary[]): string {
    return positions.map((media) => `${!creatorOf(media) && media.year ? `${nameOf(media)} (${media.year})` : nameOf(media)}\n`).join('');
}

/** Opens the file so a spreadsheet reads the accents as UTF-8. */
const BYTE_ORDER_MARK = '\uFEFF';

const CSV_COLUMNS = ['title', 'year', 'type', 'provider', 'ref', 'imdb_id', 'id'];

/** A field quoted where a comma, a quote or a line break would split it. */
function csvField(value: string | number | null): string {
    const text = value === null ? '' : String(value);

    return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

/**
 * A list as a spreadsheet (RFC 4180): a header, then a position per row with
 * the ids another service or the API knows it by.
 */
export function listCsv(positions: ListItemEntry[]): string {
    const rows = positions.map(({ media, imdbId, mediaItemId }) =>
        [nameOf(media), media.year, media.type, media.provider, media.ref, imdbId, mediaItemId].map(csvField).join(','),
    );

    return `${BYTE_ORDER_MARK}${[CSV_COLUMNS.join(','), ...rows].map((row) => `${row}\r\n`).join('')}`;
}

/** Characters no file system takes in a name, and the control ones. */
const UNSAFE_IN_FILE_NAMES = /[\\/:*?"<>|\u0000-\u001f]/g;

/**
 * The list's title as a file name with the given extension, without what a
 * file name cannot hold; `fallback` names the file when nothing is left.
 */
export function listFileName(title: string, extension: 'txt' | 'csv', fallback: string): string {
    const name = title.replace(UNSAFE_IN_FILE_NAMES, '').trim().replace(/^\.+/, '');

    return `${name || fallback}.${extension}`;
}
